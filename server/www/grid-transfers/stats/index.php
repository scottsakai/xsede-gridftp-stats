<?php

/* index.php - REST handler for gridftp stats
 * generate stats for a given quarter
 * looks like /stattype/year/quarter
 */

// DB connection info
// the file below is a php script and must define() the following
// define('DB_DSN',  'pgsql:host=yourdbhost.example.com;dbname=yourdbname');
// define('DB_USER', 'gridxfer_load');
// define('DB_PASS', '_SCRUBBED_PASSWORD_');
require_once('/var/secrets/grid-transfers-db.php');


// get the input from the user (this is usually from a rewrite rule)
$elements = explode('/', $_SERVER['PATH_INFO']);
$method = $_SERVER['REQUEST_METHOD'];

// this is read-only. we don't accept anything but GET
if( $method != "GET" )
{
    oops("Only GET accepted here");
}


// xsede-quarterly stat type
switch( $elements[1] )
{
    case 'xsede-quarterly':
    printStatsXsedeQuarterly();
    break;

    default:
    oops("Unknown stat type. Known stat types:\n  xsede-quarterly");
}



/* print the xsede quarterly stats
 * For each SP: (from Chris Jordan)
 * - Total number of Transfers
 * - Total amount of data transferred in TB
 * - Average throughput in mbps
 * - Average throughput in mbps of transfers >10MB.
 */
function printStatsXsedeQuarterly()
{
    global $elements;

    // args from request
    $year = '';
    $quarter = '';

    // import if available
    if( isset($elements[2]) ) $year = $elements[2];
    if( isset($elements[3]) ) $quarter = $elements[3];

    // check format
    if( ! preg_match('/^\d\d\d\d$/', $year) )
    {
	oops("Incorrect year format. e.g. 2014");
    }
    if( ! preg_match('/^[1234]$/', $quarter) )
    {
	oops("Incorrect quater format. e.g. 4");
    }


    // let's do this.
    // dbConnect should throw an exception if something goes wrong
    $dbh = dbConnect();


    // need to get start/end dates so db can use indexes
    // a quarter is 3 months.
    $start = '';
    $end = '';

    try
    {
	$sth = $dbh->prepare("SELECT 
          date(?) + ? * '3 months'::interval as start,  
          date(?) + ? * '3 months'::interval as end
        ");
	$sth->execute(array("$year-01-01", $quarter - 1, "$year-01-01", $quarter));
	$row = $sth->fetch(PDO::FETCH_ASSOC);
	$start = $row['start'];
	$end = $row['end'];
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to generate quarter date ranges");
    }
 
    $out = array();
    $out['description'] = "XSEDE quarterly stats";
    $out['date_range']['start'] = $start;
    $out['date_range']['end'] = $end;

    // suck in rows of interest into a temp table
    try
    {
	$sth = $dbh->prepare("CREATE TEMP TABLE grid_transfers_cache AS
	  SELECT
	    start_time, end_time, bytes, type, dest_hosts
	  from
	    grid_transfers
	  where
	    start_time >= ? and
	    start_time < ? and
	    protocol = 'gridftp' and
	    type in ('ESTO','ERET','STOR','RETR')
	");
	$sth->execute(array($start, $end));
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to generate cache table");
    }


    // query 1: total number of transfers
    try
    {
	$out[0]['description'] = 'Total Number of Transfers by Site';
	$sth = $dbh->prepare("SELECT
	  a.sitename as site, count(*) as transfers from
	  grid_transfers_cache t, grid_xsede_addrs a 
	  where
	    t.dest_hosts && a.hosts
	  group by 
	    a.sitename
	  order by
	    a.sitename asc
	");
	$sth->execute();
	while($row = $sth->fetch(PDO::FETCH_ASSOC)) $out[0]['results'][] = $row;
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to query number of transfers");
    }

    
    // query 2: total amount of data transferred by site
    try
    {
	$out[1]['description'] = 'Total TB Transferred by Site';
	$sth = $dbh->prepare("SELECT
	  a.sitename as site, sum(bytes) / 2^40 as tb_transferred from
	  grid_transfers_cache t, grid_xsede_addrs a 
	  where
	    t.dest_hosts && a.hosts
	  group by 
	    a.sitename
	  order by
	    a.sitename asc
	");
	$sth->execute();
	while($row = $sth->fetch(PDO::FETCH_ASSOC)) $out[1]['results'][] = $row;
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to query number of tb transferred");
    }


    // query 3: average throughput in MB/s by site
    try
    {
	$out[2]['description'] = 'Average Throughput by Site (MB/s)';
	$sth = $dbh->prepare("SELECT
	  a.sitename as site, avg( (bytes/2^20) / extract(epoch from end_time - start_time)) as avg_mbyte_sec from
	  grid_transfers_cache t, grid_xsede_addrs a 
	  where
	    t.dest_hosts && a.hosts
	  group by 
	    a.sitename
	  order by
	    a.sitename asc
	");
	$sth->execute();
	while($row = $sth->fetch(PDO::FETCH_ASSOC)) $out[2]['results'][] = $row;
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to query number of tb transferred");
    }


    // query 4: average throughput in MB/s by site with >10MB xfer
    try
    {
	$out[3]['description'] = 'Average Throughput by Site for transfers > 10MB (MB/s)';
	$sth = $dbh->prepare("SELECT
	  a.sitename as site, avg( (bytes/2^20) / extract(epoch from end_time - start_time)) as avg_mbyte_sec from
	  grid_transfers_cache t, grid_xsede_addrs a 
	  where
	    t.bytes > 10 * (2^20) and
	    t.dest_hosts && a.hosts
	  group by 
	    a.sitename
	  order by
	    a.sitename asc
	");
	$sth->execute();
	while($row = $sth->fetch(PDO::FETCH_ASSOC)) $out[3]['results'][] = $row;
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to query number of tb transferred");
    }
    
    print(json_encode($out));
    header(':', true, 200);
    exit();
   
}


/* connect to the db, returns a PDO handle */
function dbConnect()
{
    try
    {
	$dbh = new PDO(DB_DSN, DB_USER, DB_PASS, array( PDO::ATTR_PERSISTENT => false));
	$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    catch(Exception $e)
    {
	error_log("Caught exception: " . $e->getMessage());
	oops("Unable to connect to the database");      
    }

    return $dbh;
}


// call this when things go wrong
function oops($msg)
{
    print("$msg\n");
    error_log($msg);
    header(':', true, 400);
    exit();
}



?>

