<?php
/* index.php - REST interface for submitting gridftp logs 
 *
 * this script assumes that the httpd will perform any necessary
 * authentication.
 *
 * this script also assumes that clients will not send logs for another host
 * for cross-site use, the script should at least store PHP_AUTH_USER 
 * in each row to detect malicious or misconfigured behavior.
 *
 * this script has two modes of operation:
 * 1) "have you seen this file?" - sha1 hash POSTed in variable 'sha1hash'
 *     returns status code 200 (OK) if already seen
 *     returns status code 204 (no content) if not already seen
 *     returns status code 400 (bad request) otherwise
 *
 * 2) "here are my logs" - raw gridftp-xfer.log (or a subset of one) submitted
 *     via form file upload.  Name should be in variable 'logfile', though the
 *     actual name isn't used anywhere.
 *     returns status code 200 (OK) if insertion succeeded
 *     returns status code 400 (bad request) otherwise
 *
 */


// DB connection info
// the file below is a php script and must define() the following
// define('DB_DSN',  'pgsql:host=yourdbhost.example.com;dbname=yourdbname');
// define('DB_USER', 'gridxfer_load');
// define('DB_PASS', '_SCRUBBED_PASSWORD_');
require_once('/var/secrets/grid-transfers-db.php');


/* call this when encountering an error condition */
function oops($msg)
{
    print("$msg\n");
    error_log($msg);
    header(':', true, 400);
    exit();
}


/* start processing */

// the file should come in under the name 'logfile'
// or a hash under sha1hash
if( !isset($_FILES['logfile']) && !isset($_REQUEST['sha1hash']) )
{
    oops("Missing input: file upload (logfile) or form input (sha1hash)\n");
}


// if handling a file, verify upload was OK and get its hash
if( isset($_FILES['logfile']) )
{
    //the file should have been uploaded successfully
    if( $_FILES['logfile']['error'] != 0 )
    {
	oops("Upload failed. PHP error code: " . $_FILES['logfile']['error']);
    }

    // we'll need this later.
    $logfile = $_FILES['logfile']['tmp_name'];


    // get checksum
    $hash = strtolower(sha1_file($logfile));
}

// not handling a file, just a hash check
else
{
    $hash = trim(strtolower($_REQUEST['sha1hash']));
}


// connect to db
try
{
    $dbh = new PDO(DB_DSN, DB_USER, DB_PASS, array( PDO::ATTR_PERSISTENT => true));
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}
catch(Exception $e)
{
    error_log("Caught exception: " . $e->getMessage());
    oops("Unable to connect to the database");	    
}


// check if we saw hash already
// this will catch both the "have you seen this already" inquiries
// as well as a braindead client that didn't bother to check first
try
{
    $sth = $dbh->prepare("SELECT count(*) as hits from grid_transfers_hashes where sha1hash = ?");
    $sth->execute(array($hash));
    $row = $sth->fetch(PDO::FETCH_ASSOC);
}
catch(Exception $e)
{
    error_log("Caught exception: " . $e->getMessage());
    oops("Unable to query hashes");
}

if( $row{'hits'} > 0 )
{
    // just silently say "we got it"
    header(':', true, 200);
    exit();
}   a

// if not handling a file (just an inquiry), stop here!
else if( !isset($logfile) )
{
    // 204: no content
    header(':', true, 204);
    exit();
}


// going to insert. start transaction 
if( !$dbh->beginTransaction() )
{
    error_log("Database does not support transactions.");
    oops("Database does not support transactions");
}


// parse and insert
try
{
    $sth = $dbh->prepare("INSERT INTO grid_transfers ( 
	start_time, end_time, protocol,
	server_hostname, dest_hosts, username, 
	client_software, nl_event, filename, 
	buffer, block, bytes, 
	volume, streams, stripes, 
	type, code) 
	
	values(?, ?, 'gridftp', 
	?, ?, ?, 
	?, ?, ?,
	?, ?, ?, 
	?, ?, ?, 
	?, ?)");
}
catch(Exception $e)
{
    error_log("Caught exception: " . $e->getMessage());
    oops("Unable to prepare insert statement");
}


// read the file one line at a time. the file may be huge, and we don't have a lot of memory.
// open
$fh = fopen($logfile, "r");
if( !$fh )
{
    oops("Unable to open uploaded file for reading");
}
// for each line...
while( !feof($fh) )
{
    $line = trim(fgets($fh));

    // split into components
    if( 1 !== preg_match('/^DATE=(\d+\.\d+) HOST=(\S+) PROG=(.*) NL\.EVNT=(.*) START=(\d+\.\d+) USER=(\S+) FILE=(.*) BUFFER=(\d+) BLOCK=(\d+) NBYTES=(\d+) VOLUME=(.*) STREAMS=(\d+) STRIPES=(\d+) DEST=\[(.*)\] TYPE=(\S+) CODE=(\d+)/', $line, $match) )
    { 
	continue;
    }

    // assign to some useful variables in case we need to do stuff later.
    $end_time = $match[1];
    $server_hostname = $match[2];
    $client_software = $match[3];
    $nl_event = $match[4];
    $start_time = $match[5];
    $username = $match[6];
    $filename = $match[7];
    $buffer = $match[8];
    $block = $match[9];
    $bytes = $match[10];
    $volume = $match[11];
    $streams = $match[12];
    $stripes = $match[13];
    $dest_hosts = $match[14];
    $type = $match[15];
    $code = $match[16];

    // end time is in a weird format
    if( false === preg_match('/(\d\d\d\d)(\d\d)(\d\d)(\d\d)(\d\d)(\d\d\.\d+)$/', $end_time, $match) ) next;
    $end_time = sprintf("%s-%s-%s %s:%s:%s", $match[1], $match[2], $match[3], $match[4], $match[5], $match[6]);

    // start time is in a weird format
    if( false === preg_match('/(\d\d\d\d)(\d\d)(\d\d)(\d\d)(\d\d)(\d\d\.\d+)$/', $start_time, $match) ) next;
    $start_time = sprintf("%s-%s-%s %s:%s:%s", $match[1], $match[2], $match[3], $match[4], $match[5], $match[6]);

    // dest hosts can be a lists of IPs, separated by commas
    $dest_hosts = '{' . $dest_hosts . '}';

    // bind and go.
    try
    {
	$sth->bindValue(1, $start_time);
	$sth->bindValue(2, $end_time);
	$sth->bindValue(3, $server_hostname);
	$sth->bindValue(4, $dest_hosts);
	$sth->bindValue(5, $username);
	$sth->bindValue(6, $client_software);
	$sth->bindValue(7, $nl_event);
	$sth->bindValue(8, $filename);
	$sth->bindValue(9, $buffer);
	$sth->bindValue(10, $block);
	$sth->bindValue(11, $bytes);
	$sth->bindValue(12, $volume);
	$sth->bindValue(13, $streams);
	$sth->bindValue(14, $stripes);
	$sth->bindValue(15, $type);
	$sth->bindValue(16, $code);
    
	$sth->execute();
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to insert rows");
    }
}


// note that we saw hash
// and commit
try
{
    $sth = $dbh->prepare("INSERT INTO grid_transfers_hashes (start_time, sha1hash) values(now(), ?)");
    $sth->execute(array($hash));
    $dbh->commit();
}
catch(Exception $e)
{
    error_log("Caught exception: " . $e->getMessage());
    oops("Unable to store hash");
}

// great success!
//print_r($_FILES);
?>
