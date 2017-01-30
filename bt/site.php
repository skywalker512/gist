//添加SSL配置
function SetSSLConf(){
	$siteName = I('siteName');
	$file = '/www/server/'.$_SESSION['server_type'].'/conf/vhost/'.$siteName.'.conf';
	$conf = file_get_contents($file);
	if($_SESSION['server_type'] == 'nginx'){
		$nginxVersion = trim(@file_get_contents('/www/server/nginx/version.pl'));
		$onSSL = ($nginxVersion == '1.8.1' || $nginxVersion == '-Tengine2.1.2' || $nginxVersion == '-Tengine2.2.0') ? '':"";
                //11111111111111111111111111
		$sslStr = "#error_page 404/404.html;{$onSSL}\n		ssl_certificate      	key/$siteName/key.csr;\n		ssl_certificate_key  key/$siteName/key.key;\n		ssl_protocols TLSv1 TLSv1.1 TLSv1.2;\n		ssl_ciphers 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES256-SHA384:ECDHE-ECDSA-AES256-SHA:ECDHE-RSA-AES256-SHA:DHE-RSA-AES128-SHA256:DHE-RSA-AES128-SHA:DHE-RSA-AES256-SHA256:DHE-RSA-AES256-SHA:ECDHE-ECDSA-DES-CBC3-SHA:ECDHE-RSA-DES-CBC3-SHA:EDH-RSA-DES-CBC3-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA256:AES256-SHA256:AES128-SHA:AES256-SHA:DES-CBC3-SHA:!DSS';\n		ssl_prefer_server_ciphers on;\n if (\$server_port !~ 443){\n			rewrite ^/.*\$ https://\$host\$uri;\n		}\n		error_page 497  https://\$host\$uri;\n";
		if(strpos($conf,'ssl_certificate')){
			returnJson(true,'SSL开启成功!');
		}
	
		$conf = str_replace('#error_page 404/404.html;',$sslStr,$conf);
		$rep = "/listen\s+([0-9]+).*;/";
		preg_match_all($rep,$conf,$tmp);
		if(!in_array('443',$tmp[1])){
			$ssl = ($nginxVersion == '1.8.1' || $nginxVersion == '-Tengine2.1.2' || $nginxVersion == '-Tengine2.2.0') ? "\n		listen 443 ssl http2;":"\n		listen 443 ssl http2;";
			$conf = str_replace($tmp[0][0],$tmp[0][0].$ssl,$conf);
		}
	}else{
		if(strpos($conf,'SSLCertificateFile')){
			returnJson(true,'SSL开启成功!');
		}
		
		$find = M('sites')->where("name='$siteName'")->find();
		$rep = "/:[0-9]+\,*/";
		$domains = trim(preg_replace($rep, ' ', $find['domain']));
		$path = $find['path'];
		$index = str_replace(',',' ',$find['index']);
		$rep = "/php-cgi-([0-9]{2,3})\.sock/";
		preg_match($rep,$conf,$tmp);
		$version = $tmp[1];
		$sslStr = <<<EOT
<VirtualHost *:443>
	ServerAdmin webmaster@example.com
	DocumentRoot "{$path}"
	ServerName SSL.{$siteName}
	ServerAlias {$domains}
	ErrorLog "/www/wwwlogs/{$siteName}-error_log"
	CustomLog "/www/wwwlogs/{$siteName}-access_log" combined
	
	#SSL
	SSLEngine On
	SSLCertificateFile conf/key/$siteName/key.csr
	SSLCertificateKeyFile conf/key/$siteName/key.key
	
	#PHP
	<FilesMatch \\.php$>
	        SetHandler "proxy:unix:/tmp/php-cgi-{$version}.sock|fcgi://localhost"
	</FilesMatch>
	
	#PATH
	<Directory "{$path}">
	    SetOutputFilter DEFLATE
	    Options FollowSymLinks
	    AllowOverride All
	    Order allow,deny
	    Allow from all
	    DirectoryIndex {$index}
	</Directory>
</VirtualHost>
EOT;
				
		
		$conf = $conf."\n".$sslStr;
		apacheAddPort('443');
		
	}
	
	if (file_put_contents('/tmp/read.tmp', $conf)) {
		SendSocket('ExecShell|\\cp -a '.$file.' /tmp/backup.conf');
		SendSocket("FileAdmin|SaveFile|" . $file);
		if($_SESSION['server_type'] == 'nginx'){
			$isError = checkNginxConf();
		}else{
			$isError = checkHttpdConf();
		}
		
		if($isError !== true){
			SendSocket('ExecShell|\\cp -a /tmp/backup.conf '.$file);
			SendSocket("FileAdmin|ChmodFile|" . $file . '|root');
			returnJson(false,'配置文件错误: <br><a style="color:red;">'.str_replace("\n",'<br>',$isError).'</a>');
		}
		
		$sql = M('firewall');
		if(!$sql->where("port='443'")->getCount()){
			SendSocket("Firewall|AddFireWallPort|443|TCP|".$ps);
			$data = array('port'=>'443','ps'=>'https','addtime'=>date('Y-m-d H:i:s'));
			$sql->add($data);
		}
		WriteLogs('网站管理', '网站['.$siteName.']开启SSL成功!');
		returnJson(true,'SSL开启成功!');	
	}
	returnJson(false,'SSL开启失败!');
	
}

//添加apache端口
function apacheAddPort($port){
	$filename = '/www/server/apache/conf/httpd.conf';
	$allConf = file_get_contents($filename);
	$rep = "/Listen\s+([0-9]+)\n/";
	preg_match_all($rep,$allConf,$tmp);
	if(!in_array($port,$tmp[1])){
		$allConf = str_replace($tmp[0][0],$tmp[0][0]."Listen ".$port."\n",$allConf);
		file_put_contents('/tmp/read.tmp', $allConf);
		SendSocket("FileAdmin|SaveFile|" . $filename);
	}
}

//清理SSL配置
function CloseSSLConf(){
	$siteName = I('siteName');
	$file = '/www/server/'.$_SESSION['server_type'].'/conf/vhost/'.$siteName.'.conf';
	$conf = file_get_contents($file);
	if($_SESSION['server_type'] == 'nginx'){
//2222222222222222222222222222222222222222222222222222222222
		$rep = "/\s+ssl_certificate\s+.+;\s+ssl_certificate_key\s+.+;\s+ssl_protocols\s+.+;\s+ssl_ciphers\s+.+;\s+ssl_prefer_server_ciphers\s+.+;/";
		$conf = preg_replace($rep,'',$conf);
		$rep = "/\s+ssl\s+on;/";
		$conf = preg_replace($rep,'',$conf);
		$rep = "/\s+error_page\s497.+;/";
		$conf = preg_replace($rep,'',$conf);
		$rep = "/\s+if.+server_port.+\n.+\n\s+}\s/";
		$conf = preg_replace($rep,'',$conf);
		$rep = "/\s+listen\s+443.*;/";
		$conf = preg_replace($rep,'',$conf);
	}else{
		$rep = "/\n<VirtualHost \*\:443>(.|\n)*<\/VirtualHost>/";
		$conf = preg_replace($rep,'',$conf);
	}
	
	if (file_put_contents('/tmp/read.tmp', $conf)) {
        $result = SendSocket("FileAdmin|SaveFile|" . $file);
        WriteLogs('网站管理', '网站['.$siteName.']关闭SSL成功!');
        returnJson(true,'SSL已关闭!');
    }
    returnJson(false,'关闭失败!');
}
