<?php
require(dirname(__FILE__).'/Cache.class.php');




class DingtalkModel {
	private $corpid;
  	private $corpSecret;
  	private $agentid;
  	
  	private $errMsg;
  	
  	private $cache;

	
	public function __construct() {
		
		$this->corpid = CORPID;
		$this->corpSecret = SECRET;
		$this->agentid = AGENTID;

		$this->cache = new Cache(7100, dirname(dirname(__FILE__)).'/cache/');
		//user test
		return;
	}	
	
    public function getConfig(){
        $corpId = $this->corpid;
        $agentId = $this->agentid;
        $nonceStr = 'abcdefg';
        $timeStamp = time();
        $url = $this->curPageURL();
        
        $corpAccessToken = $this->getAccessToken();
        
        if (!$corpAccessToken){
        	$this->errMsg = "[getConfig] ERR: no corp access token";
            return false;
        }
        
        $ticket = $this->getTicket($corpAccessToken);
        $signature = $this->sign($ticket, $nonceStr, $timeStamp, $url);
        
        $config = array(
            'url' => $url,
            'nonceStr' => $nonceStr,
            'agentId' => $agentId,
            'timeStamp' => $timeStamp,
            'corpId' => $corpId,
            'signature' => $signature);
        
        return json_encode($config, JSON_UNESCAPED_SLASHES);
    }
	




    protected function curPageURL(){
        $pageURL = 'http';

        if (array_key_exists('HTTPS',$_SERVER)&&$_SERVER["HTTPS"] == "on"){
            $pageURL .= "s";
        }
        $pageURL .= "://";

        if ($_SERVER["SERVER_PORT"] != "80"){
            $pageURL .= $_SERVER["SERVER_NAME"] . ":" . $_SERVER["SERVER_PORT"] . $_SERVER["REQUEST_URI"];
        }else{
            $pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
        }
        return $pageURL;
    }



    public function getAccessToken(){
		/**
		* 缓存accessToken。accessToken有效期为两小时，需要在失效前请求新的accessToken。
		*/
		$now = time();
		$mark = md5($this->corpid.'corp_access_token'.$this->corpSecret);
		
		$ca = $this->cache->get($mark);
		
        $accessToken = empty($ca['corp_access_token'])?'':$ca['corp_access_token'];
        if (!$accessToken || ($ca['expire_time'] < $now)){
            
            $gate = 'https://oapi.dingtalk.com/gettoken';
            $url = $this->joinParams($gate, array('corpid' => $this->corpid, 'corpsecret' => $this->corpSecret));
            $response = $this->curl_get_contents($url);
            $response = json_decode($response);
            $accessToken = $response->access_token;
            
			$data['corp_access_token'] = $accessToken;
			$data['expire_time'] = $now+7100;          
        
            $this->cache->put('corp_access_token', $data);
        }
        return $accessToken;
    }


	  
    public function getTicket($accessToken){
		/**
		* 缓存jsTicket。jsTicket有效期为两小时，需要在失效前请求新的jsTicket。
		*/
		$now = time();
    	$mark = md5($this->corpid.'js_ticket'.$this->corpSecret);
    	
        $ca = $this->cache->get($mark);
        
        $jsticket = empty($ca['jsapi_ticket'])?'':$ca['jsapi_ticket'];
        
        if (!$jsticket || ($ca['expire_time'] < $now)){
        	
        	$gate = 'https://oapi.dingtalk.com/get_jsapi_ticket';
        	
        	$url = $this->joinParams($gate, array('type' => 'jsapi', 'access_token' => $accessToken));
        	
            $response = $this->curl_get_contents($url);
            $response = json_decode($response);
            
            $this->check($response);
            
            $jsticket = $response->ticket;

			$data['jsapi_ticket'] = $jsticket;
			$data['expire_time'] = $now+7100;
            
            $this->cache->put($mark,$data);
            
        }
        return $jsticket;
    }


    public function getUserInfoCore($userid){
    	$accessToken = $this->getAccessToken();

//    	$gate = 'https://oapi.dingtalk.com/user/getuserinfo';
//    	$url = $this->joinParams($gate, array("access_token" => $accessToken, "code" => $code));
//		$response = $this->curl_get_contents($url);
//		
//		$usercode = json_decode($response);
//		if(empty($usercode->userid)){
//			return false;
//		}
		
    	$gate = 'https://oapi.dingtalk.com/user/get';
    	$url = $this->joinParams($gate, array("access_token" => $accessToken, "userid" => $userid));
		
		$response = $this->curl_get_contents($url);
				
        return $response;

    }

	public function getUserDayoff($userid,$startday,$enday){
		$accessToken = $this->getAccessToken();
		
		$gate = 'https://oapi.dingtalk.com/attendance/list';
    	$url = $this->joinParams($gate,
    		array("access_token" => $accessToken));	
		
		
		$options['userId'] = $userid;
		$options['workDateFrom'] = $startday;
		$options['workDateTo'] = $enday;
		
		$response = $this->curl_post_contents($url,json_encode($options));
		
		return $response;
	}


    public function getUserInfo($code){
    	$accessToken = $this->getAccessToken();

    	$gate = 'https://oapi.dingtalk.com/user/getuserinfo';
    	$url = $this->joinParams($gate, array("access_token" => $accessToken, "code" => $code));
		$response = $this->curl_get_contents($url);

        return $response;
    }
	
	public function sendToConversation($options){
    	$accessToken = $this->getAccessToken();

    	$gate = 'https://oapi.dingtalk.com/message/send_to_conversation';
    		
    	$url = $this->joinParams($gate,
    		array("access_token" => $accessToken));	
        	
		$response = $this->curl_post_contents($url,json_encode($options));
		
		return $response;
	}
		
	
    protected function check($res){
        if ($res->errcode != 0){
            exit("Failed: " . json_encode($res));
        }
    }	
	
	protected function sign($ticket, $nonceStr, $timeStamp, $url){
        $plain = 'jsapi_ticket=' . $ticket .
            '&noncestr=' . $nonceStr .
            '&timestamp=' . $timeStamp .
            '&url=' . $url;
        return sha1($plain);
    }
	
	protected function curl_get_contents($url){
		if(empty($url)){
			return false;
		}
		$options[CURLOPT_RETURNTRANSFER] = true;
		$options[CURLOPT_TIMEOUT] = 5;
		$ch = curl_init($url);
		curl_setopt_array($ch,$options);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);

		$html = curl_exec($ch);
		curl_close($ch);
		if($html === false){
			return false;
		}
		return $html;		
	}

	protected function curl_post_contents($url,$data){
		$headers = array(
		"Content-type: application/json;charset='utf-8'",
		"Accept: application/json",
		"Cache-Control: no-cache",
		"Pragma: no-cache",
		);



		$ch = curl_init(); //初始化curl
		
		
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);//设置头信息
		curl_setopt($ch, CURLOPT_URL, $url);//设置链接
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);//设置是否返回信息
		curl_setopt($ch, CURLOPT_POST, 1);//设置为POST方式
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data);//POST数据
		$response = curl_exec($ch);//接收返回信息
		if(curl_errno($ch)){//出错则显示错误信息
			return false;
		}
		curl_close($ch); //关闭curl链接
		return $response;//显示返回信息
	}
	
    protected function joinParams($gate, $params){
        $url = $gate;
        if (count($params) > 0){
            $url = $url . "?";
            foreach ($params as $key => $value){
                $url = $url . $key . "=" . $value . "&";
            }
            $length = count($url);
            if ($url[$length - 1] == '&'){
                $url = substr($url, 0, $length - 1);
            }
        }
        return $url;
    }
	
		
	protected function redirect($url, $time=0, $msg='') {
	    //多行URL地址支持
	    $url        = str_replace(array("\n", "\r"), '', $url);
	    if (empty($msg))
	        $msg    = "系统将在{$time}秒之后自动跳转到{$url}！";
	    if (!headers_sent()) {
	        // redirect
	        if (0 === $time) {
	            header('Location: ' . $url);
	        } else {
	            header("refresh:{$time};url={$url}");
	            echo($msg);
	        }
	        exit();
	    } else {
	        $str    = "<meta http-equiv='Refresh' content='{$time};URL={$url}'>";
	        if ($time != 0)
	            $str .= $msg;
	        exit($str);
	    }
	}
}
