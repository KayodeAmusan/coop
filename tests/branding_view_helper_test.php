<?php
require_once __DIR__.'/helpers.php';require_once __DIR__.'/../includes/branding_view_helper.php';
test('branding derives readable foreground links focus and navigation for light dark and saturated colors',function(){
 foreach(['#ffffff','#ffff00','#00ffff','#000000','#ff0000','#2448a0','#00ff00','#123456']as$color){$tokens=cooperativeBrandingPalette(['primary_color'=>$color,'accent_color'=>'#abcdef']);assertSameValue(strtolower($color),$tokens['--color-primary']);assertSameValue(true,cooperativeColorContrast($color,$tokens['--color-on-primary'])>=4.5);assertSameValue(true,cooperativeColorContrast($tokens['--color-link'],'#ffffff')>=4.5);assertSameValue(true,cooperativeColorContrast($tokens['--focus-color'],'#ffffff')>=3);assertSameValue(true,cooperativeColorContrast($tokens['--color-nav'],'#ffffff')>=12);}
});
test('branding color input cannot inject CSS or markup',function(){
 foreach(['red','#fff','#ffffff;display:none','url(https://invalid.example)','</style><script>bad()</script>']as$color){$tokens=cooperativeBrandingPalette(['primary_color'=>$color,'accent_color'=>$color]);assertSameValue('#2448a0',$tokens['--color-primary']);assertSameValue('#7041a6',$tokens['--color-accent']);}
});
finishTests();

