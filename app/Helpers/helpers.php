<?php

// Globally accessible functions
use App\Country;
use App\IpBlacklist;
use App\Services\TokenModal;
use App\Services\TokenParser;
use App\VisaType;
use Carbon\Carbon;
use Illuminate\Support\Facades\Session;

function user(): ?App\User{
  return auth()->user();
}

function flash($message, $type, $title = null, $is_modal = true){
  if($type == 's' || $type == 'good'){
    $type = 'success';
  }
  if($type == 'e' || $type == 'err'){
    $type = 'error';
  }

  if(!strlen($title)){
    $title = $type === 'success' ? 'Success!' : 'Oops...';
  }

  $icon = $type; // warning, error, success, info
  Session::put('flash_msg', compact('message', 'type', 'is_modal', 'title', 'icon'));
}

function get_flash_and_clear(){
  return Session::pull('flash_msg', false);
}

function flash_isset(){
  return Session::exists('flash_msg');
}

function json_error($message, $http_code = 422){
  return response(['error' => true, 'message' => $message], $http_code);
}

function report_error($message, ...$data){
  if(app()->runningUnitTests()){
    return '123456789';
  }

  Sentry\withScope(function(\Sentry\State\Scope $scope) use ($message, $data): void{
    foreach($data as $arg_num => $datum){
      $scope->setExtra('arg' . $arg_num, $datum);
    }

    app('sentry')->captureMessage($message);
  });

  return \Sentry::getLastEventID();
}

function client_ip(){
  return \Illuminate\Support\Facades\Request::ip();
}

function country_code_from_ip($ip = null): ?string{
  if($ip === null){
    $ip = client_ip();
  }

  return IpBlacklist::getCountryCodeFromIpCached($ip);
}

function get_rand(array $list){
  shuffle($list);

  return array_first($list);
}

function mix_ivisa($path, $manifestDirectory){
  if(app()->environment('local') && file_exists(public_path($manifestDirectory . '/hot'))){
    // Webpack Hot-Reloading Enabled (Dev Environment)
    $domain = rtrim(url()->to('/'), '/');
    return $domain . ':4844/' . ltrim($path, '/');
  }

  return mix($path, $manifestDirectory);
}

function get_10_digit_date($date, $allow_null = false){
  if($date === null || $date === ''){
    if($allow_null){
      return null;
    }
    else {
      throw new \Exception('Date expected but not provided e73812');
    }
  }
  else if($date instanceof \Carbon\Carbon){
    return $date->toDateString();
  }
  else if(is_string($date) && strlen($date) >= 10){
    return substr($date, 0, 10);
  }
  else {
    throw new \Exception('Invalid date');
  }
}

/**
 * @param $date
 * @param bool $allow_null
 * @return Carbon|null
 * @throws Exception
 */
function carbon($date, $allow_null = false){
  if($date === null || $date === ''){
    if($allow_null){
      return null;
    }
    else {
      throw new \Exception('Date expected but not provided e73812');
    }
  }
  else if($date instanceof \Carbon\Carbon){
    return (clone $date);
  }
  else if(is_numeric($date)){
    // Assume Unix Timestamp
    return \Carbon\Carbon::createFromTimestamp($date);
  }
  else {
    return \Carbon\Carbon::parse($date);
  }
}

function format_date($date, $format = 'date'){
  if(!($date instanceof Carbon)){
    $date = carbon($date, true);
  }

  if(!($date instanceof Carbon)){
    return null;
  }

  $timezone = 'UTC';
  if(user() && strlen(user()->timezone)){
    $timezone = user()->timezone;
  }

  if($format === 'datetime'){
    $format = 'Y-m-d g:ia T';
  }
  else if($format === 'date'){
    $format = 'Y-m-d';
  }

  return $date->setTimezone($timezone)->format($format);
}

// Returns STRING with exactly 2 decimal places
function money($money): string{
  if(!is_numeric($money)){
    throw new \Exception('Invalid money amount');
  }

  $money = sprintf("%01.2f", $money);
  return ($money === '-0.00') ? '0.00' : $money;
}

function clean_money_input($money){
  return str_replace(',', '', trim($money, '$'));
}

function humanize($str){
  $str = str_replace('_', ' ', $str);

  return title_case($str);
}

function create_nested_array($arr, $keys, $nested_name = 'details'){
  if(!isset($arr[$nested_name])){
    $arr[$nested_name] = [];
  }

  if(!is_array($arr[$nested_name])){
    throw new \Exception('Unable to created nested array with key ' . $nested_name);
  }

  foreach($keys as $key){
    if(isset($arr[$key])){
      if(!is_null($arr[$key])){
        $arr[$nested_name][$key] = $arr[$key];
      }
      unset($arr[$key]);
    }
  }
  return $arr;
}

function format_currency($amount, $currency, $decimals = 2){
  return $currency . ' ' . number_format($amount, $decimals);
}

/**
 * Currency preference for the current user
 * @return string
 */
function get_currency(): string{
  $currency = request()->get('currency');
  if(strlen($currency)){
    return $currency;
  }

  if(request()->hasCookie('vuex')){
    $vuex = json_decode_array(request()->cookie('vuex'));
    if(isset($vuex['settings']['currency'])){
      return $vuex['settings']['currency'];
    }
  }

  return 'USD';
}

/**
 * Language currently being used on the website
 * @return string
 */
function get_locale(): string{
  return Illuminate\Support\Facades\App::getLocale();
}

/**
 * Currently supported languages (locales)
 * @return array
 */
function get_locales(): array{
  $list = [];
  foreach(\App\Services\Language::$locales as $locale => $info){
    $list[$locale] = $info['name'];
  }

  return $list;
}

/**
 * @param string|array $json
 * @return array
 * @throws Exception
 */
function json_decode_array($json): array{
  if($json === null || $json === "null"){
    return [];
  }
  else if(is_array($json)){
    return $json;
  }

  $arr = json_decode($json, true);

  if(json_last_error() !== JSON_ERROR_NONE){
    throw new \Exception('JSON parse error: ' . json_last_error_msg());
  }

  return is_array($arr) ? $arr : [];
}

function typecast($type, $val, $null_okay = false){
  if($type === 'int' || $type === 'integer'){
    return (int) $val;
  }
  if($type === 'string'){
    if(is_array($val)){
      return json_encode($val);
    }
    else {
      return (string) $val;
    }
  }

  if($type === 'float'){
    return (float) $val;
  }

  if($type === 'boolean' || $type === 'bool'){
    if($val === "true"){
      return true;
    }
    if($val === "false"){
      return false;
    }
    return $val ? true : false;
  }

  return $val;
}

function get_country_codes(){
  return App\Country::getCountryCodes();
}

function get_country_codes_translated($locale = null){
  if($locale === null){
    $locale = get_locale();
  }

  $codes = get_country_codes();
  if($locale === 'en'){
    return $codes;
  }

  foreach($codes as $code => $name){
    $codes[$code] = __($name);
  }

  asort($codes);
  return $codes;
}

/**
 * Get english name for a locale code
 * @param string|null $locale
 * @return string
 */
function pretty_locale_name(?string $locale): string{
  return get_locales()[strtolower($locale)] ?? $locale ?? '';
}

function get_hostname(){
  $hostname = parse_url(config('app.url'), PHP_URL_HOST);

  if(app()->runningInConsole() === false){
    $hostname = app("request")->header('x-ivisa-cookie-cutter') ?? app("request")->getHost();
  }

  return $hostname;
}

/**
 * Used to get the current domain, language subdomain, or cookie scope domain
 * @param string type 2-letter locale code or 'cookie'
 */
function build_domain_name(string $type, ?string $hostname = null){
  if($hostname === null){
    $hostname = get_hostname();
  }

  // Is it an IP address?
  if(filter_var($hostname, FILTER_VALIDATE_IP)){
    return $hostname;
  }

  $parts = explode('.', $hostname);
  $isSubdomainLangOrWww = in_array($parts[0], array_keys(get_locales()), true) || $parts[0] === 'www';

  if(strlen($type) === 2){
    // Specific language subdomain
    $newSub = strtolower($type);
    if($newSub === 'en'){
      // English site has special rules
      $newSub = null;
      if(($isSubdomainLangOrWww && count($parts) === 3) || (!$isSubdomainLangOrWww && count($parts) === 2)){
        // This is a top level site like ivisa.com or ivisa.test
        if($parts[count($parts) - 1] !== 'test'){
          $newSub = 'www';
        }
      }
    }

    if($isSubdomainLangOrWww === true){
      if($newSub !== null){
        $parts[0] = $newSub;
      }
      else {
        unset($parts[0]);
      }
    }
    else {
      if($newSub !== null){
        array_unshift($parts, $newSub);
      }
    }
  }
  else if($type === 'cookie'){
    if($isSubdomainLangOrWww === true){
      unset($parts[0]);
    }
  }
  else {
    throw new \Exception('Invalid domain type');
  }

  $hostname = implode('.', $parts);

  return $hostname;
}

/**
 * Domain used for setting cookie scope
 */
function get_cookie_domain(){
  $tld = build_domain_name('cookie');

  if(filter_var($tld, FILTER_VALIDATE_IP)){
    return $tld;
  }

  return '.' . $tld;
}

/**
 * Get full country name for a country abbreviation
 * @param string|null $country_code
 * @return string
 */
function pretty_country_name(?string $country_code, string $locale = null): string{
  if(empty($country_code)){
    return ''; // Return empty string if $country_code is null or empty string
  }

  $name = get_country_codes()[strtoupper($country_code)] ?? $country_code;
  if(($locale ?? get_locale()) === 'en'){
    return $name;
  }

  return __($name);
}

/**
 * Route helper for generating URLs for Visa Category (aka Learn More) pages
 */
function visa_category_route($countryCodeOrVisaCategory): string{
  $url = "/";
  if($countryCodeOrVisaCategory instanceof App\VisaCategory){
    $url = $countryCodeOrVisaCategory->url;
  }
  else if(strlen($countryCodeOrVisaCategory) === 2){
    // Get default VisaCategory for this country
    $visaCategory = App\VisaCategory::getDefaultCategoryCached($countryCodeOrVisaCategory);
    if($visaCategory === null){
      $url = "/";
    }
    else {
      $url = $visaCategory->url;
    }
  }

  return url($url);
}

/**
 * Route helper for generating URLs for Visa Application pages
 */
function visa_application_route($countryCode, $params = [], App\VisaCategory $visaCategory = null): string{
  if($visaCategory !== null && $visaCategory->affiliate_url !== null){
    return visa_category_route($visaCategory) . '?applyAffiliate=1';
  }

  $url = '/apply-online/' . strtoupper($countryCode);

  $legacy = ['AR', 'AU', 'AZ', 'BH', 'BR', 'KH', 'CA', 'CN', 'CU', 'DO', 'EG', 'ET', 'HK', 'IN', 'CI', 'KE', 'KW', 'KG', 'MY', 'MX', 'MM', 'NZ', 'OM', 'RU', 'SG', 'LK', 'TJ', 'TW', 'TH', 'TR', 'AE', 'UG', 'UA', 'US', 'UZ', 'VN', 'ZM', 'ZW'];
  if(in_array($countryCode, $legacy, true)){
    $url = '/apply-online/' . Country::getSlug($countryCode);
  }

  if(!in_array($countryCode, VisaType::getCountriesWeSellVisasForCached(), true)){
    return route("public.apply.index");
  }

  return rtrim(url('/'), '/') . $url . (count($params) ? '?' . http_build_query($params) : '');
}

/**
 * Normalize the URLs that are in the DB
 * @param string $url
 * @return string
 */
function url_normalize(string $url = null): ?string{
  if(strlen($url) < 1 || $url === null){
    return null;
  }
  if($ret = parse_url($url)){
    if(!isset($ret["scheme"])){
      return strtolower("http://{$url}");
    }
    else {
      return strtolower($url);
    }
  }
}

/**
 * Allow access to private methods of objects
 */
function invokeMethod(&$object, $methodName, array $parameters = array()){
  $reflection = new \ReflectionClass(get_class($object));
  $method = $reflection->getMethod($methodName);
  $method->setAccessible(true);

  return $method->invokeArgs($object, $parameters);
}

/**
 * Allow access to private variables of objects
 */
function change_private_class_variable(&$object, $varName, $newVal): void{
  $reflection = new \ReflectionClass(get_class($object));
  $prop = $reflection->getProperty($varName);
  $prop->setAccessible(true);
  $prop->setValue($object, $newVal);
}

function safe_divide($numerator, $denominator, ?int $round = null){
  $r = $denominator === 0 ? 0 : ($numerator / $denominator);
  return $round === null ? $r : round($r, $round);
}

/**
 * String containing lowercase a-z
 */
function random_string($length = 3){
  $str = '';
  for($i = 0; $i < $length; $i++){
    $str .= chr(rand(97, 122));
  }
  return $str;
}

function array_without_keys($arr){
  if($arr instanceof Illuminate\Support\Collection){
    $arr = $arr->all();
  }

  return array_values($arr);
}

function markdownToHtml($markdown): string{
  if(!strlen($markdown)){
    return '';
  }
  $markdown = strip_tags($markdown);

  // Allow custom variables for dynamic values
  $markdown = strlen($markdown) ? TokenParser::replaceVariableTokens($markdown) : '';

  $html = app('Parsedown')->text($markdown);

  // Allow custom variables for dynamic modals
  $html = strlen($html) ? TokenModal::setModalLinks($html) : '';

  // Remove outer <p> tags
  if(substr($html, 0, 3) === '<p>' && substr_count($html, '<p>') === 1){
    $html = str_replace_first('<p>', '', $html);
    $html = str_replace_last('</p>', '', $html);
  }

  if(strpos($html, '<a') !== false){
    // Adding a ^ at the beginning of link text will make it open in a new tab
    $html = preg_replace('/(<a [^<>]+)>\^/', '$1 target="_blank">', $html);

    // Adding a @ at the beginning of link text will make it a no-follow link
    $html = preg_replace('/(<a [^<>]+)>\@/', '$1 target="_blank" rel="nofollow">', $html);

    // Adding button: will display a link as a button
    $html = preg_replace('%<(a[^<>]+)>button:([^<>]+)(<\/a>)%im', '<div class="markdown-button-wrapper" style="margin: 45px 0;text-align: center;"><$1 class="btn btn-success">$2$3</div>', $html);
  }
  if(strpos($html, '[youtube=') !== false){
    $html = preg_replace('/\[youtube=([^,]+),width=(\d+),height=(\d+)\]/', '<iframe width="$2" height="$3" src="https://www.youtube.com/embed/$1" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>', $html);
  }
  if(strpos($html, '{covid-dashboard}') !== false){
    $html = preg_replace('/{covid-dashboard}/','<iframe width="100%" height="825" src="https://www.arcgis.com/apps/opsdashboard/index.html#/bda7594740fd40299423467b48e9ecf6" scrolling="no"></iframe>', $html);
  }

  return $html;
}

function git_hash(){
  return substr(@file_get_contents(base_path('.git/refs/heads/master')), 0, 7);
}

/**
 * When running unit tests the APP_ENV=testing which may cause problems
 * for certain code that really needs to know what server it's running on
 */
function get_real_environment(){
  $envFile = file_get_contents(base_path(".env"));
  if(preg_match('/APP_ENV=(.+)$/im', $envFile, $regs)){
    return trim($regs[1]);
  }
  else {
    return "production";
  }
}

/**
 * Get a route on a particular language sub-domain
 *
 * Note: Not all routes are available in all languages, such as blogs and embassy directory
 */
function routeLocale(string $locale, string $routeName, $parameters = []): string{
  $route = route($routeName, $parameters, false);

  $domains = App\Services\iVisaRouter::getLocaleSubdomains();
  if(!isset($domains[$locale])){
    throw new Exception("Invalid routeLocale");
  }

  return $domains[$locale]['url_base'] . $route;
}

/**
 * Binary Response Inline
 */
function binaryResponseInline($bytes, $filename = null, $mime = 'application/pdf'){
  header('Content-Disposition: inline;filename=' . ($filename ?? mt_rand() . '.pdf'));
  header('Content-type: ' . $mime);
  die($bytes);
}

/**
 * Route Name (used for Critical CSS)
 */
function getCriticalRouteName(){
  $name = optional(\Route::getCurrentRoute())->getName() ?? 'unknown';
  if(preg_match('/public\.visa_category\.\d+$/im', $name)){
    return "public.visa_category.show";
  }
  if(preg_match('/public\.apply\.[A-Z]{2}$/im', $name)){
    return "public.apply.show";
  }

  return $name;
}

/*
 * Accepts a number of days and converts to a string representation in years
 * */
function days_to_string_translated($days): string{
  try {
    $explodedDays = explode(' - ', $days);

    foreach($explodedDays as $key => $num_days){
      $years = $num_days / 365;

      if($years < 1){
        $explodedDays[$key] = $num_days . ' ' . trans(str_plural('day', $num_days));
        continue;
      }

      $years = round($years, 1);

      $explodedDays[$key] = $years . ' ' . trans(str_plural('year', $years));
    }

    return implode(' - ', $explodedDays);
  } catch(\Exception $e){
    return $days . ' ' . trans('days');
  }
}

/*
 * Accepts any character string and converts it into UTF-8
 */
function transliterate($input){
  $input = transliterator_transliterate('Any-Latin; Latin-ASCII', $input);
  $input = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $input);

  return $input;
}

/**
 * Get the site theme colors from Tailwind.js
 */
function get_theme_color_codes(): array{
  $tailwind_js = file_get_contents(base_path('tailwind.js'));
  if(preg_match('/let colors = ({[^{}]+})/', $tailwind_js, $regs)){
    return json_decode_array($regs[1]);
  }
  throw new \Exception('Unable to parse theme color codes');
}

/**
 * Get the current route name
 */
function get_current_route_name(): string{
  return optional(\Route::getCurrentRoute())->getName() ?? 'unknown';
}

function country_code_picker(): array{
  $countryCodePicker = [];
  foreach(get_country_codes() as $code => $name){
    $countryCodePicker[] = ['id' => $code, 'name' => $code . ' - ' . $name, 'value' => $code];
  }
  return $countryCodePicker;
}
