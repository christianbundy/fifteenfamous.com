<?php

/**
 * Twitter-API-PHP : Simple PHP wrapper for the v1.1 API
 * 
 * PHP version 5.3.10
 * 
 * @category Awesomeness
 * @package  Twitter-API-PHP
 * @author   James Mallison <me@j7mbo.co.uk>
 * @license  http://opensource.org/licenses/gpl-license.php GNU Public License
 * @link     http://github.com/j7mbo/twitter-api-php
 */
class TwitterAPIExchange
{
    private $oauth_access_token;
    private $oauth_access_token_secret;
    private $consumer_key;
    private $consumer_secret;
    private $postfields;
    private $getfield;
    protected $oauth;
    public $url;

    /**
     * Create the API access object. Requires an array of settings::
     * oauth access token, oauth access token secret, consumer key, consumer secret
     * These are all available by creating your own application on dev.twitter.com
     * Requires the cURL library
     * 
     * @param array $settings
     */
    public function __construct(array $settings)
    {
        if (!in_array('curl', get_loaded_extensions())) 
        {
            throw new Exception('You need to install cURL, see: http://curl.haxx.se/docs/install.html');
        }
        
        if (!isset($settings['oauth_access_token'])
            || !isset($settings['oauth_access_token_secret'])
            || !isset($settings['consumer_key'])
            || !isset($settings['consumer_secret']))
        {
            throw new Exception('Make sure you are passing in the correct parameters');
        }

        $this->oauth_access_token = $settings['oauth_access_token'];
        $this->oauth_access_token_secret = $settings['oauth_access_token_secret'];
        $this->consumer_key = $settings['consumer_key'];
        $this->consumer_secret = $settings['consumer_secret'];
    }
    
    /**
     * Set postfields array, example: array('screen_name' => 'J7mbo')
     * 
     * @param array $array Array of parameters to send to API
     * 
     * @return TwitterAPIExchange Instance of self for method chaining
     */
    public function setPostfields(array $array)
    {
        if (!is_null($this->getGetfield())) 
        { 
            throw new Exception('You can only choose get OR post fields.'); 
        }
        
        if (isset($array['status']) && substr($array['status'], 0, 1) === '@')
        {
            $array['status'] = sprintf("\0%s", $array['status']);
        }
        
        $this->postfields = $array;
        
        return $this;
    }
    
    /**
     * Set getfield string, example: '?screen_name=J7mbo'
     * 
     * @param string $string Get key and value pairs as string
     * 
     * @return \TwitterAPIExchange Instance of self for method chaining
     */
    public function setGetfield($string)
    {
        if (!is_null($this->getPostfields())) 
        { 
            throw new Exception('You can only choose get OR post fields.'); 
        }
        
        $search = array('#', ',', '+', ':');
        $replace = array('%23', '%2C', '%2B', '%3A');
        $string = str_replace($search, $replace, $string);  
        
        $this->getfield = $string;
        
        return $this;
    }
    
    /**
     * Get getfield string (simple getter)
     * 
     * @return string $this->getfields
     */
    public function getGetfield()
    {
        return $this->getfield;
    }
    
    /**
     * Get postfields array (simple getter)
     * 
     * @return array $this->postfields
     */
    public function getPostfields()
    {
        return $this->postfields;
    }
    
    /**
     * Build the Oauth object using params set in construct and additionals
     * passed to this method. For v1.1, see: https://dev.twitter.com/docs/api/1.1
     * 
     * @param string $url The API url to use. Example: https://api.twitter.com/1.1/search/tweets.json
     * @param string $requestMethod Either POST or GET
     * @return \TwitterAPIExchange Instance of self for method chaining
     */
    public function buildOauth($url, $requestMethod)
    {
        if (!in_array(strtolower($requestMethod), array('post', 'get')))
        {
            throw new Exception('Request method must be either POST or GET');
        }
        
        $consumer_key = $this->consumer_key;
        $consumer_secret = $this->consumer_secret;
        $oauth_access_token = $this->oauth_access_token;
        $oauth_access_token_secret = $this->oauth_access_token_secret;
        
        $oauth = array( 
            'oauth_consumer_key' => $consumer_key,
            'oauth_nonce' => time(),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_token' => $oauth_access_token,
            'oauth_timestamp' => time(),
            'oauth_version' => '1.0'
            );
        
        $getfield = $this->getGetfield();
        
        if (!is_null($getfield))
        {
            $getfields = str_replace('?', '', explode('&', $getfield));
            foreach ($getfields as $g)
            {
                $split = explode('=', $g);
                $oauth[$split[0]] = $split[1];
            }
        }
        
        $base_info = $this->buildBaseString($url, $requestMethod, $oauth);
        $composite_key = rawurlencode($consumer_secret) . '&' . rawurlencode($oauth_access_token_secret);
        $oauth_signature = base64_encode(hash_hmac('sha1', $base_info, $composite_key, true));
        $oauth['oauth_signature'] = $oauth_signature;
        
        $this->url = $url;
        $this->oauth = $oauth;
        
        return $this;
    }
    
    /**
     * Perform the actual data retrieval from the API
     * 
     * @param boolean $return If true, returns data.
     * 
     * @return string json If $return param is true, returns json data.
     */
    public function performRequest($return = true)
    {
        if (!is_bool($return)) 
        { 
            throw new Exception('performRequest parameter must be true or false'); 
        }
        
        $header = array($this->buildAuthorizationHeader($this->oauth), 'Expect:');
        
        $getfield = $this->getGetfield();
        $postfields = $this->getPostfields();

        $options = array( 
            CURLOPT_HTTPHEADER => $header,
            CURLOPT_HEADER => false,
            CURLOPT_URL => $this->url,
            CURLOPT_RETURNTRANSFER => true
            );

        if (!is_null($postfields))
        {
            $options[CURLOPT_POSTFIELDS] = $postfields;
        }
        else
        {
            if ($getfield !== '')
            {
                $options[CURLOPT_URL] .= $getfield;
            }
        }

        $feed = curl_init();
        curl_setopt_array($feed, $options);
        $json = curl_exec($feed);
        curl_close($feed);

        if ($return) { return $json; }
    }
    
    /**
     * Private method to generate the base string used by cURL
     * 
     * @param string $baseURI
     * @param string $method
     * @param array $params
     * 
     * @return string Built base string
     */
    private function buildBaseString($baseURI, $method, $params) 
    {
        $return = array();
        ksort($params);
        
        foreach($params as $key=>$value)
        {
            $return[] = "$key=" . $value;
        }
        
        return $method . "&" . rawurlencode($baseURI) . '&' . rawurlencode(implode('&', $return)); 
    }
    
    /**
     * Private method to generate authorization header used by cURL
     * 
     * @param array $oauth Array of oauth data generated by buildOauth()
     * 
     * @return string $return Header used by cURL for request
     */    
    private function buildAuthorizationHeader($oauth) 
    {
        $return = 'Authorization: OAuth ';
        $values = array();
        
        foreach($oauth as $key => $value)
        {
            $values[] = "$key=\"" . rawurlencode($value) . "\"";
        }
        
        $return .= implode(', ', $values);
        return $return;
    }

}


$settings = array(
    'oauth_access_token' => "20654473-em3YCgLRqjkTYgnqk1AXuEEroM0mrwEVz8nTW1sgG",
    'oauth_access_token_secret' => "unOCLWVxq49mXe0UCqURg8t6KsbYzYYpzGdufLKm2ao",
    'consumer_key' => "PLj28QRAx3QleaioYeng5A",
    'consumer_secret' => "tnKtbX9I6SvpMkgLdhfUrUfvdhncUKypknZCsZZHQ"
    );


// Setup
$url = 'https://api.twitter.com/1.1/search/tweets.json';
$twitter = new TwitterAPIExchange($settings);
$mysqli = new mysqli("localhost", "cbundy", "k4U3x9V5", "cbundy_fifteenfamous");

if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: (" . $mysqli->connect_errno . ") " . $mysqli->connect_error;
}

$query = "SELECT * FROM `winners` ORDER BY `id` DESC LIMIT 1";
$result = $mysqli->query($query);
$user = $result->fetch_array(MYSQLI_ASSOC);
$end_now = $user['end'];

if (time() > $end_now) {
    $requestMethod = 'GET';
    $getfield = '?q=%40FifteenFamous&result_type=recent&count=100';

    $response = $twitter->setGetfield($getfield)
    ->buildOauth($url, $requestMethod)
    ->performRequest();
    $obj = json_decode($response,true);
    $number = count($obj['statuses']);
    $candidates = array();
    $pics = array();

    for ($i = 0; $i < $number; $i++) {
    $name = $obj['statuses'][$i]['user']['screen_name'];
    array_push($candidates, $name);

    $pic = $obj['statuses'][$i]['user']['profile_image_url'];
    array_push($pics, $pic);
    }

    $unique_names = array_unique($candidates);
    $unique_pics = array_unique($pics);

    //bios bruh
    $requestMethod = 'GET';
    $getfield = '?q=%40ChristianBundy';

    $response = $twitter->setGetfield($getfield)
    ->buildOauth($url, $requestMethod)
    ->performRequest();
    $obj = json_decode($response,true);
    
    $names_num = intval(count($unique_names)) - 1;
    $winner = rand(0,$names_num);
    $end = time() + (15* 60);
    $query = "INSERT INTO `winners` (`username`, `img`, `end`) VALUES ('" . $unique_names[$winner] . "','" . $unique_pics[$winner] . "','" . $end . "')";
    $mysqli->query($query);    
}


?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="">
<meta name="author" content="">

<title>@<?= $user['username'] ?> is famous!</title>

<!-- Bootstrap core CSS -->
<link href="css/bootstrap.css" rel="stylesheet">

<!-- Custom styles for this template -->
<link href="css/style.css" rel="stylesheet">
<script src="//ajax.googleapis.com/ajax/libs/jquery/1.10.1/jquery.min.js"></script>
<script src="js/bootstrap.min.js"></script>
<script>
/**
 * Plugin kkcountdown counts down to specific dates in the future.
 *
 * @example
 * $(".come-class").kkcountdown();
 *
 * @type jQuery
 *
 * @name kkcountdown
 * @author Krzysztof Furtak http://krzysztof-furtak.pl/
 * @version 1.3
 * 
 * Documentation: http://krzysztof-furtak.pl/2010/05/kk-countdown-jquery-plugin/
 * 
 */
 (function($){$.fn.kkcountdown=function(k){var l={dayText:'day ',daysText:'days ',hoursText:':',minutesText:':',secondsText:'',textAfterCount:'---',oneDayClass:false,displayDays:true,displayZeroDays:true,addClass:false,callback:false,warnSeconds:60,warnClass:false};var k=$.extend(l,k);var m=new Array();this.each(function(){var a=$(this);var b=$(document.createElement('span')).addClass('kkcountdown-box');var c=$(document.createElement('span')).addClass('kkc-dni');var d=$(document.createElement('span')).addClass('kkc-godz');var e=$(document.createElement('span')).addClass('kkc-min');var f=$(document.createElement('span')).addClass('kkc-sec');var g=$(document.createElement('span')).addClass('kkc-dni-text');var h=$(document.createElement('span')).addClass('kkc-godz-text');var i=$(document.createElement('span')).addClass('kkc-min-text');var j=$(document.createElement('span')).addClass('kkc-sec-text');if(k.addClass!=false){b.addClass(k.addClass)}h.html(k.hoursText);i.html(k.minutesText);j.html(k.secondsText);b.append(c).append(g).append(d).append(h).append(e).append(i).append(f).append(j);a.append(b);kkCountdownInit(a)});function kkCountdownInit(a){var b=0;if(a.id===undefined){a.id='kk_'+Math.random(new Date().getTime())}if(a.id in m)b=m[a.id];else b=a.attr('data-seconds');if(b===undefined){var c=new Date();c=Math.floor(c.getTime()/1000);var d=a.attr('data-time');if(d===undefined)d=a.attr('time');b=d-c}m[a.id]=b-1;if(k.warnClass&&b<k.warnSeconds){a.addClass(k.warnClass)}if(b<0){a.html(k.textAfterCount);if(k.callback){k.callback()}}else if(b<=24*60*60){setTimeout(function(){kkCountDown(true,a,b);kkCountdownInit(a)},1000)}else{setTimeout(function(){kkCountDown(false,a,b);kkCountdownInit(a)},1000)}}function kkCountDown(a,b,c){var d=naprawaCzasu(c%60);c=Math.floor(c/60);var e=naprawaCzasu(c%60);c=Math.floor(c/60);var f=naprawaCzasu(c%24);c=Math.floor(c/24);var g=c;if(a&&k.oneDayClass!=false){b.addClass(k.oneDayClass)}if(g==0&&!k.displayZeroDays){}else if(g==1){b.find('.kkc-dni').html(g);b.find('.kkc-dni-text').html(k.dayText)}else{b.find('.kkc-dni').html(g);b.find('.kkc-dni-text').html(k.daysText)}b.find('.kkc-godz').html(f);b.find('.kkc-min').html(e);b.find('.kkc-sec').html(d)}function naprawaCzasu(a){s='';if(a<10){a='0'+a}return a}}})(jQuery);
</script>

<script>
    $(function() {
      $(".countdown").kkcountdown({
        dayText : 'day ',
        daysText : 'days ',
        hoursText : '',
        minutesText : ':',
        secondsText : '',
        oneDayClass : 'one-day',
        displayZeroDays : false,
        textAfterCount: '',
        callback: function() {
          location.reload(true);
        }
    });
      window.setTimeout(function() {
        $(".countdown").fadeIn(500);
    },1000);

    $(".profile-wrapper").stop(true, true).animate({
            opacity:"1"
        },800);

  });
</script>
</head>

<body id="body" style="zoom: 1;">

  <div class="container-narrow">
    <div class="header clearfix">    
    <div class="pull-right">
          <h3 class="countdown" data-time="<?= $user['end'] ?>"></h3>
      </div>
      <div class="pull-left">
          <h3 class="text-muted">@FifteenFamous</h3>
      </div>
  </div>
</div>
<div class="row marketing">
  <div class="container-narrow">

      <p class="lead no-margin">Every fifteen minutes, we make somebody famous.</p>
      <p class="lead text-muted no-padding">Make a tweet mentioning <a href="http://twitter.com/FifteenFamous">@FifteenFamous</a> for a chance to be next.</p>
  </div>
</div>

<div id="them" class="profile-wrapper">
  <div class="profile container-narrow">
    <div class="media">
      <a class="pull-left" href="#">
        <img class="profile-thumbnail media-object" src="<?= $user['img'] ?>" />
    </a>
    <div class="media-body">
        <div class="pull-left"><h3>@<?= $user['username'] ?></h3>
          <a href="https://twitter.com/intent/tweet?screen_name=<?= $user['username'] ?>" class="twitter-mention-button profile-follow" data-related="<?= $user['username'] ?>">Tweet to @<?= $user['username'] ?></a>
<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script>
</div>
<div class="pull-right">
<p class="lead text-muted no-padding">
This could be you!
</p>
</div>
 
</div>
</div>
<a class="twitter-timeline" data-dnt="true" data-widget-id="361307160640102402" data-screen-name="<?= $user['username'] ?>" data-tweet-limit="3">Tweets</a>
<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+"://platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}(document,"script","twitter-wjs");</script>

<p id="you" class="lead">What are you waiting for? All the cool kids are doing it.</p>
<p class="lead text-muted bottom-padding">We choose someone from the most recent 100 tweets with <a href="http://twitter.com/FifteenFamous">@FifteenFamous</a>.</p>
<a class="twitter-timeline" data-dnt="true" href="https://twitter.com/search?q=%40FifteenFamous" data-widget-id="361319481110302720" data-tweet-limit="3">Tweets about "@FifteenFamous"</a>
<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+"://platform.twitter.com/widgets.js";fjs.parentNode.insertBefore(js,fjs);}}(document,"script","twitter-wjs");</script>




</div>
<div class="block align-center" >
<a href="https://twitter.com/share" class="twitter-share-button" data-url="http://fifteenfamous.com" data-text="I'm on @FifteenFamous!" data-size="large" data-count="none">Tweet</a>
<script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script></div>
</div>
<div class="footer">
  <div class="row">
      <div class="container-narrow">
        <div class="pull-left links"><a href="https://twitter.com/ChristianBundy" class="twitter-follow-button" data-show-count="false">Follow @ChristianBundy</a>
            <script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script></div>

            <div class="pull-right">
                <a height="350" href="https://twitter.com/Bram_Jacob" class="twitter-follow-button" data-show-count="false">Follow @Bram_Jacob</a>
                <script>!function(d,s,id){var js,fjs=d.getElementsByTagName(s)[0],p=/^http:/.test(d.location)?'http':'https';if(!d.getElementById(id)){js=d.createElement(s);js.id=id;js.src=p+'://platform.twitter.com/widgets.js';fjs.parentNode.insertBefore(js,fjs);}}(document, 'script', 'twitter-wjs');</script></div>
            </div>

        </div>
        <div class="container-narrow bump-top">
            <div class="pull-left bump">Built with <a href="http://getbootstrap.com">Twitter Bootstrap 3</a>.</div>
        </div>
    </div>
    <script>
  (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
  (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
  m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
  })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

  ga('create', 'UA-42726766-1', 'fifteenfamous.com');
  ga('send', 'pageview');

</script>
</body></html>