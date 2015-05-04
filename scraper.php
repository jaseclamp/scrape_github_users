<?

if(!isset( $_SERVER['MORPH_USER_SESSION'] ) && !isset( $_SERVER['MORPH__GH_SESS'] ))
{
    echo "you need to set the session variable in scraper settings under 'MORPH_USER_SESSION' and 'MORPH__GH_SESS' copy paste from your cookie - no line breaks\n";
    echo "showing all variables \n";
    var_dump(  get_defined_vars() ); 
    die;
}

require 'rb.php';
require 'simple_html_dom.php';  
R::setup('sqlite:data.sqlite');

//R::nuke();

function url_get_contents ($url) {

    //$url = 'http://myhttp.info/';

    if (!function_exists('curl_init')){ 
        die('CURL is not installed!');
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $headers = array();
    $headers[] = "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
    $headers[] = "Accept-Encoding: gzip, deflate";
    $headers[] = "Accept-Language: en-US,en;q=0.5";
    $headers[] = "Connection: keep-alive";
    $headers[] = "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10.10; rv:37.0) Gecko/20100101 Firefox/37.0";
    $headers[] = "Cookie: logged_in=yes; _ga=GA1.2.1030011918.1427507368; _octo=GH1.1.1923536842.1427507370; user_session=".$_SERVER['MORPH_USER_SESSION']."; dotcom_user=jaseclamp; tz=Australia%2FBrisbane; _gh_sess=".$_SERVER['MORPH__GH_SESS']."; _gat=1";
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch,CURLOPT_ENCODING, '');

    $output = curl_exec($ch);
    curl_close($ch);
    return $output;
}

$topics = array('PHP','JavaScript','CSS');
$locations = array('brisbane','sydney','qld','nsw','australia','zealand','queensland','new+south+wales','victoria','melbourne');
$baseurl = 'https://github.com';



//load uids into array to save 1000s of sqlite queries
$uids = R::getAll('select uid from data');
if(count($uids)<1) $uids = array();
else {
foreach($uids as $uid) $_uids[] = $uid['uid'];
	$uids = $_uids; unset($_uids);
}

foreach($topics as $topic):
foreach($locations as $location):

	//results limited to 1k so specific is good.
	$url = 'https://github.com/search?utf8=%E2%9C%93&q=language%3A'.$topic.'+location%3A'.$location.'&type=Users&ref=advsearch';

	//get first page users.. recurse by looking at pagination next
	getUsers($url);


endforeach;
endforeach;



function getUsers($url){

	$html = url_get_contents($url);
	$dom = new simple_html_dom();
	$dom->load($html);

	foreach($dom->find('div.user-list-item') as $user)
	{
		$uid = substr( $user->find('a[href^=/]',0)->href , 1 ); 

        echo "\n" . $GLOBALS['topic'] . " :: " . $GLOBALS['location'] . " : " . $uid;

		//skip if have
		if( in_array( $uid , $GLOBALS['uids'] )  ) { echo " -- already have"; continue; }
            
        $users = R::dispense('data');
        $users->profile_url = $GLOBALS['baseurl'] . '/' . $uid;
        $users->uid = $uid;
        $users->lanuage = $GLOBALS['topic'];
        
        //go detail page, easier to get rest of info from that.
        if( getUserDetail($users) > 0 )
        {    
           $GLOBALS['uids'][] = $uid;
           echo " -- saved";
        }


	}

    //is there another page??? get that beast.
    if($nextpage = $dom->find('div.pagination a.next_page',0)->href)
        getUsers($GLOBALS['baseurl'].$nextpage);

}


function getUserDetail($users)
{
    $html = url_get_contents( $users->url );
    $dom = new simple_html_dom();
    $dom->load($html);

    $users->name = $dom->find('span.vcard-fullname',0)->plaintext;
    $users->location = $dom->find('li[itemprop=homeLocation]',0)->plaintext;
    $users->worksfor = $dom->find('li[itemprop=worksFor]',0)->plaintext;
    $users->url = $dom->find('li[itemprop=url] a',0)->href;
    $users->joined = $dom->find('time.join-date',0)->datetime;

    $users->avatar = $dom->find('img.avatar',0)->src; 

    $users->folowers = $dom->find('div.vcard-stats a.vcard-stat',0)->find('strong',0)->plaintext;
    $users->starred = $dom->find('div.vcard-stats a.vcard-stat',1)->find('strong',0)->plaintext;
    $users->following = $dom->find('div.vcard-stats a.vcard-stat',2)->find('strong',0)->plaintext;

    $users->contributions = preg_replace( "/[^\d]/", "", $dom->find('div.contrib-column-first span.contrib-number',0) );

    return R::store($users);

}




function geocodeUsers () {
    
    if( isset( $GLOBALS['OVER_QUERY_LIMIT'] ) ) return false;
    
    $users = R::getAll("select * from data where lat = ?", array('') );
    
    foreach($users as $user){
        
        if($user['lat'] != '') continue; //redundant check
        if($user['lng'] != '') continue; 
        if($user['location']=='') continue; 
        
        echo "\n".$GLOBALS['toc']." Geocoding ".$user['name']." at ".$user['location'];
        
        //if we got it before reuse.
        $result = R::findOne( 'data', ' lat != :x AND lat != :blank AND location = :loc ', array( ':x'=>'XXX', ':blank'=>'', ':loc'=> $user['location'] ) );
        //if the result is not null that means its already in the db so continue on to the next one in this loop
        if(!is_null($result)) { 
            echo " -- already got"; 
            $lat = $result->lat;
            $lng = $result->lng;
        }else{
            $addr = urlencode($user['location']);
            $addr = str_replace("%2C","",$addr);
            $url = 'http://maps.googleapis.com/maps/api/geocode/json?sensor=false&address='.$addr;
            $get = file_get_contents($url);
            $records = json_decode($get,TRUE);
            
            if ( $records['status'] == 'OK' ) {
                //neat_r($records['results'][]);
                $lat = $records['results'][0]['geometry']['location']['lat'];
                $lng = $records['results'][0]['geometry']['location']['lng'];
                echo " -- ".$lat."-".$lng."";
            } elseif ( $records['status'] == 'OVER_QUERY_LIMIT' ) {
                echo " -- OVER_QUERY_LIMIT";
                $GLOBALS['OVER_QUERY_LIMIT'] = true;
                return false;
            }else{
                echo " -- XXX";
                //neat_r($records); die;
                $lat = 'XXX'; 
                $lng = 'XXX'; 
            }
        }
        $_users = R::load('data',$user['id']);
        $_users->lat = $lat; 
        $_users->lng = $lng; 
        R::store($_users); 
    } 
}

?>