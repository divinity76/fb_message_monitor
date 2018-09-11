<?php
declare(strict_types = 1);
$username; // set by init later, but need em global.
$password;
function print_usage_and_die() {
	global $argv;
	// var_dump($argv);
	fprintf ( STDERR, "usage: %s --credentialsfile=creds.txt\n(creds are newline based, email on line 1, password on line 2. you can also use the shorter `-c` )\n", $argv [0] );
	die ( 1 );
}
function init() {
	global $argv;
	global $username;
	global $password;
	
	if (! function_exists ( 'curl_init' )) {
		throw new Exception ( "this script requires the php-curl extension!" );
	}
	if (! function_exists ( 'json_decode' )) {
		throw new Exception ( "this script requires the php-json extension!" );
	}
	if (! class_exists ( 'DOMDocument', false )) {
		throw new Exception ( "this script requires the php-xml extension!" );
	}
	if (version_compare ( PHP_VERSION, '7.0.0', '<' )) {
		throw new Exception ( "this script requires PHP version >= 7.0.0 , but " . PHP_VERSION . " running!" );
	}
	if (strtolower ( php_sapi_name () ) !== 'cli') {
		throw new Exception ( "this script should only run in CLI, but runs in: " . php_sapi_name () );
	}
	$args = getopt ( "c::", [ 
			// "username::",
			// "password::",
			"credentialsfile::" 
	] );
	if (empty ( $args )) {
		print_usage_and_die ();
	}
	$cfile = NULL;
	if (! empty ( $args ['c'] )) {
		$cfile = $args ['c'];
	} elseif (! empty ( $args ['credentialsfile'] )) {
		$cfile = $args ['credentialsfile'];
	} else {
		fprintf ( STDERR, "unable to read credentialsfile location! ( -c=path/to_file.txt )\n" );
		die ( 1 );
	}
	$creds_raw = NULL;
	if ($cfile === '-') {
		$creds_raw = stream_get_meta_data ( STDIN );
	} else {
		$creds_raw = file_get_contents ( $cfile );
	}
	$creds_raw = array_filter ( array_map ( 'trim', explode ( "\n", $creds_raw ) ), 'strlen' );
	if (count ( $creds_raw ) !== 2) {
		fprintf ( "error, found more than 2 newlines in credentials file! line 1 must be the email, line 2 must be the password, line 3 must not exist." );
	}
	$username = $creds_raw [0];
	$password = $creds_raw [1];
	require_once ('hhb_.inc.php'); // hhb_curl
	hhb_init (); // just better error reporting for uncaught exceptions.
}
class Fb_message_monitor {
	// ps most of this class is derived from https://github.com/divinity76/msgme/blob/master/src/php/relays/facebook.relay.php
	/** @var hhb_curl $hc */
	protected $hc;
	protected $email;
	protected $password;
	protected $logoutUrl;
	function __construct(string $email, string $password) {
		$this->email = $email;
		$this->password = $password;
		$this->hc = new hhb_curl ( '', true );
		$this->hc->setopt_array ( array (
				CURLOPT_USERAGENT => 'Mozilla/5.0 (BlackBerry; U; BlackBerry 9300; en) AppleWebKit/534.8+ (KHTML, like Gecko) Version/6.0.0.570 Mobile Safari/534.8+',
				CURLOPT_HTTPHEADER => array (
						'accept-language:en-US,en;q=0.8' 
				) 
		) );
		$this->login ();
	}
	function __destruct() {
		$this->logout ();
	}
	protected function login() {
		$hc = &$this->hc;
		$hc->setopt_array ( array (
				CURLOPT_URL => 'https://m.facebook.com/',
				CURLOPT_HTTPHEADER => array (
						'accept-language:en-US,en;q=0.8' 
				) 
		) )->exec ();
		$domd = @\DOMDocument::loadHTML ( $hc->getStdOut () );
		$form = getDOMDocumentFormInputs ( $domd, true ) ['login_form'];
		$url = $domd->getElementsByTagName ( "form" )->item ( 0 )->getAttribute ( "action" );
		$postfields = (function () use (&$form): array {
			$ret = array ();
			foreach ( $form as $input ) {
				$ret [$input->getAttribute ( "name" )] = $input->getAttribute ( "value" );
			}
			return $ret;
		});
		$postfields = $postfields (); // sorry about that, eclipse can't handle IIFE syntax.
		assert ( array_key_exists ( 'email', $postfields ) );
		assert ( array_key_exists ( 'pass', $postfields ) );
		$postfields ['email'] = $this->email;
		$postfields ['pass'] = $this->password;
		$hc->setopt_array ( array (
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => http_build_query ( $postfields ),
				CURLOPT_HTTPHEADER => array (
						'accept-language:en-US,en;q=0.8' 
				) 
		) );
		// \hhb_var_dump ($postfields ) & die ();
		$hc->exec ( $url );
		$domd = @\DOMDocument::loadHTML ( $hc->getResponseBody () );
		$xp = new \DOMXPath ( $domd );
		$InstallFacebookAppRequest = $xp->query ( "//a[contains(@href,'/login/save-device/cancel/')]" );
		if ($InstallFacebookAppRequest->length > 0) {
			// not all accounts get this, but some do, not sure why, anyway, if this exist, fb is asking "ey wanna install the fb app instead of using the website?"
			// and won't let you proceed further until you say yes or no. so we say no.
			$url = 'https://m.facebook.com' . $InstallFacebookAppRequest->item ( 0 )->getAttribute ( "href" );
			$hc->exec ( $url );
			$domd = @\DOMDocument::loadHTML ( $hc->getResponseBody () );
			$xp = new \DOMXPath ( $domd );
		}
		unset ( $InstallFacebookAppRequest, $url );
		$urlinfo = parse_url ( $hc->getinfo ( CURLINFO_EFFECTIVE_URL ) );
		$a = $xp->query ( '//a[contains(@href,"/logout.php")]' );
		if ($a->length < 1) {
			$debuginfo = $hc->getStdErr () . $hc->getStdOut ();
			$tmp = tmpfile ();
			fwrite ( $tmp, $debuginfo );
			$debuginfourl = shell_exec ( "cat " . escapeshellarg ( stream_get_meta_data ( $tmp ) ['uri'] ) . " | pastebinit" );
			fclose ( $tmp );
			throw new \RuntimeException ( 'failed to login to facebook! apparently... cannot find the logout url!  debuginfo url: ' . $debuginfourl );
		}
		$a = $a->item ( 0 );
		$url = $urlinfo ['scheme'] . '://' . $urlinfo ['host'] . $a->getAttribute ( "href" );
		$this->logoutUrl = $url;
		// logged in :)
		return;
	}
	protected function logout() {
		$this->hc->setopt ( CURLOPT_HTTPGET, 1 )->exec ( $this->logoutUrl );
	}
	function get_number_of_unread_messages()/*: int*/ {
		$html = $this->hc->setopt ( CURLOPT_HTTPGET, 1 )->exec ( 'https://m.facebook.com/' )->getStdOut ();
		file_put_contents ( 'unread.' . ( string ) time () . '.html', $html );
		$domd = @DOMDocument::loadHTML ( $html );
		$xp = new DOMXPath ( $domd );
		$query = '//*[@accesskey="4"]/descendant::span';
		$res = $xp->query ( $query );
		if ($res->length === 0) {
			return 0;
		}
		if ($res->length > 1) {
			ob_start ();
			var_dump ( $query, $res );
			foreach ( $res as $ele ) {
				var_dump ( $domd->saveHTML ( $ele ) );
			}
			$debug = ob_get_clean ();
			throw new \RuntimeException ( "xpath got more than 1 result on number of unread messages! should never happen. debug info: " . $debug );
		}
		$res = trim ( $res->item ( 0 )->textContent );
		$rex = '/^\((\d+)\)$/';
		$matches = [ ];
		if (1 !== preg_match_all ( $rex, $res, $matches )) {
			ob_start ();
			var_dump ( $rex, $domd->saveHTML ( $res->item ( 0 ) ) );
			$debug = ob_get_clean ();
			throw new \RuntimeException ( "rex failed to parse number of unread messages! debug info: " . $debug );
		}
		$unread = ( int ) ($matches [1] [0]);
		return $unread;
	}
}
function getDOMDocumentFormInputs(\DOMDocument $domd, bool $getOnlyFirstMatches = false, bool $getElements = true): array {
	// :DOMNodeList?
	if (! $getOnlyFirstMatches && ! $getElements) {
		throw new \InvalidArgumentException ( '!$getElements is currently only implemented for $getOnlyFirstMatches (cus im lazy and nobody has written the code yet)' );
	}
	$forms = $domd->getElementsByTagName ( 'form' );
	$parsedForms = array ();
	$isDescendantOf = function (\DOMNode $decendant, \DOMNode $ele): bool {
		$parent = $decendant;
		while ( NULL !== ($parent = $parent->parentNode) ) {
			if ($parent === $ele) {
				return true;
			}
		}
		return false;
	};
	// i can't use array_merge on DOMNodeLists :(
	$merged = function () use (&$domd): array {
		$ret = array ();
		foreach ( $domd->getElementsByTagName ( "input" ) as $input ) {
			$ret [] = $input;
		}
		foreach ( $domd->getElementsByTagName ( "textarea" ) as $textarea ) {
			$ret [] = $textarea;
		}
		foreach ( $domd->getElementsByTagName ( "button" ) as $button ) {
			$ret [] = $button;
		}
		return $ret;
	};
	$merged = $merged ();
	foreach ( $forms as $form ) {
		$inputs = function () use (&$domd, &$form, &$isDescendantOf, &$merged): array {
			$ret = array ();
			foreach ( $merged as $input ) {
				// hhb_var_dump ( $input->getAttribute ( "name" ), $input->getAttribute ( "id" ) );
				if ($input->hasAttribute ( "disabled" )) {
					// ignore disabled elements?
					continue;
				}
				$name = $input->getAttribute ( "name" );
				if ($name === '') {
					// echo "inputs with no name are ignored when submitted by mainstream browsers (presumably because of specs)... follow suite?", PHP_EOL;
					continue;
				}
				if (! $isDescendantOf ( $input, $form ) && $form->getAttribute ( "id" ) !== '' && $input->getAttribute ( "form" ) !== $form->getAttribute ( "id" )) {
					// echo "this input does not belong to this form.", PHP_EOL;
					continue;
				}
				if (! array_key_exists ( $name, $ret )) {
					$ret [$name] = array (
							$input 
					);
				} else {
					$ret [$name] [] = $input;
				}
			}
			return $ret;
		};
		$inputs = $inputs (); // sorry about that, Eclipse gets unstable on IIFE syntax.
		$hasName = true;
		$name = $form->getAttribute ( "id" );
		if ($name === '') {
			$name = $form->getAttribute ( "name" );
			if ($name === '') {
				$hasName = false;
			}
		}
		if (! $hasName) {
			$parsedForms [] = array (
					$inputs 
			);
		} else {
			if (! array_key_exists ( $name, $parsedForms )) {
				$parsedForms [$name] = array (
						$inputs 
				);
			} else {
				$parsedForms [$name] [] = $tmp;
			}
		}
	}
	unset ( $form, $tmp, $hasName, $name, $i, $input );
	if ($getOnlyFirstMatches) {
		foreach ( $parsedForms as $key => $val ) {
			$parsedForms [$key] = $val [0];
		}
		unset ( $key, $val );
		foreach ( $parsedForms as $key1 => $val1 ) {
			foreach ( $val1 as $key2 => $val2 ) {
				$parsedForms [$key1] [$key2] = $val2 [0];
			}
		}
	}
	if ($getElements) {
		return $parsedForms;
	}
	$ret = array ();
	foreach ( $parsedForms as $formName => $arr ) {
		$ret [$formName] = array ();
		foreach ( $arr as $ele ) {
			$ret [$formName] [$ele->getAttribute ( "name" )] = $ele->getAttribute ( "value" );
		}
	}
	return $ret;
}
function beep_until_canceled() {
	echo "beeping, press the any key to abort.";
	while ( 1 ) {
		if (is_callable ( 'ncurses_beep' )) {
			ncurses_beep ();
			sleep ( 1 );
			if (strlen ( stream_get_contents ( STDIN ) ) > 0) {
				return;
			}
		}
		echo "\x07";
		sleep ( 1 );
		if (strlen ( stream_get_contents ( STDIN ) ) > 0) {
			return;
		}
		if (is_callable ( 'ncurses_flash' )) {
			ncurses_flash ();
			sleep ( 1 );
			if (strlen ( stream_get_contents ( STDIN ) ) > 0) {
				return;
			}
		}
	}
}

init ();
stream_set_blocking ( STDIN, false );
ugly:
try {
	echo "logging in..";
	$o = new Fb_message_monitor ( $username, $password );
	echo "done.\n";
	echo "now checking every 60 seconds (roughly)\n";
	for(;;) {
		$num = $o->get_number_of_unread_messages ();
		if ($num !== 0) {
			echo "unread messages: ";
			var_dump ( $num );
			beep_until_canceled ();
			goto ugly; // don't know how much time was spent in beep_* , so want to relogin now just in case cookie session expired.
		}
		echo ".";
		sleep ( 60 );
	}
} catch ( Exception $ex ) {
	echo "something bad happened! exception! printing debug data and beeping..";
	var_dump ( $ex );
	beep_until_canceled ();
	goto ugly;
}
