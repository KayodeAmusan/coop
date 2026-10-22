<?php
/** Read-only rendering adapter. Persistence and platform authorization belong to the settings service. */
function cooperativeColorLuminance(string $hex): float {
    $rgb=[];foreach(str_split(substr($hex,1),2)as$part){$c=hexdec($part)/255;$rgb[]=$c<=0.04045?$c/12.92:(($c+0.055)/1.055)**2.4;}
    return $rgb[0]*0.2126+$rgb[1]*0.7152+$rgb[2]*0.0722;
}
function cooperativeColorContrast(string $a,string $b): float {$x=cooperativeColorLuminance($a);$y=cooperativeColorLuminance($b);return(max($x,$y)+0.05)/(min($x,$y)+0.05);}
function cooperativeReadableColor(string $color,float $minimum): string {
    $rgb=array_map('hexdec',str_split(substr($color,1),2));
    for($step=0;$step<=100;$step++){$candidate=sprintf('#%02x%02x%02x',(int)round($rgb[0]*(1-$step/100)),(int)round($rgb[1]*(1-$step/100)),(int)round($rgb[2]*(1-$step/100)));if(cooperativeColorContrast($candidate,'#ffffff')>=$minimum)return$candidate;}
    return '#000000';
}
function cooperativeBrandingPalette(array $branding): array {
    $primary=is_string($branding['primary_color']??null)&&preg_match('/\A#[a-f0-9]{6}\z/i',$branding['primary_color'])?strtolower($branding['primary_color']):'#2448a0';
    $accent=is_string($branding['accent_color']??null)&&preg_match('/\A#[a-f0-9]{6}\z/i',$branding['accent_color'])?strtolower($branding['accent_color']):'#7041a6';
    $onPrimary=cooperativeColorContrast($primary,'#ffffff')>=cooperativeColorContrast($primary,'#000000')?'#ffffff':'#000000';
    return ['--color-primary'=>$primary,'--color-accent'=>$accent,'--color-on-primary'=>$onPrimary,'--color-link'=>cooperativeReadableColor($primary,4.5),'--focus-color'=>cooperativeReadableColor($primary,4.5),'--color-nav'=>cooperativeReadableColor($primary,12)];
}
function cooperativeBrandingView(?PDO $db=null): array {
    static $view=null;if($view!==null)return$view;
    $branding=[];$helper=__DIR__.'/deployment_settings_helper.php';
    try {if(is_file($helper)||function_exists('deploymentPublicBranding')){if(!function_exists('deploymentPublicBranding'))require_once$helper;$branding=deploymentPublicBranding($db);if(!is_array($branding))$branding=[];}}catch(Throwable $error){$branding=[];}
    $name=is_string($branding['cooperative_name']??null)&&trim($branding['cooperative_name'])!==''?$branding['cooperative_name']:'Cooperative workspace';
    $safeAsset=static fn($url)=>is_string($url)&&preg_match('/\A\/?api\/branding_asset\.php\?id=[a-f0-9]{32}\z/',$url)?$url:null;
    $tokens=cooperativeBrandingPalette($branding);$nav=$tokens['--color-nav'];$tokens['--glass-nav']='rgba('.hexdec(substr($nav,1,2)).','.hexdec(substr($nav,3,2)).','.hexdec(substr($nav,5,2)).',.96)';
    $css='';foreach($tokens as$key=>$value)$css.=$key.':'.$value.';';
    return $view=['name'=>$name,'logo_url'=>$safeAsset($branding['logo_url']??null),'favicon_url'=>$safeAsset($branding['favicon_url']??null),'css'=>$css,'configured'=>($branding['configured']??false)===true];
}
