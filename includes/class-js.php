<?php
/**
 * JavaScript: delay until interaction, defer and minify.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Js {

	/** <script> types that are real JavaScript. */
	const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'module', 'text/ecmascript', 'application/ecmascript' );

	/** Scripts that are never delayed (they must run early for the page to render correctly). */
	const NEVER_DELAY = array(
		'e-lazyloaded', 'lazyloadRunObserver', 'no-js', 'document.documentElement.className',
		'fwp-loader', 'wp-emoji', 'consent', 'cookieyes', 'cookie-law-info', 'complianz',
	);

	/** @var bool */
	public $has_delayed = false;

	public function process( $html ) {
		$delay    = (bool) Settings::get( 'delay_js' );
		$defer    = (bool) Settings::get( 'defer_js' );
		$minify   = (bool) Settings::get( 'minify_js' );
		$trackers = (bool) Settings::get( 'delay_js_trackers' );
		if ( ! $delay && ! $defer && ! $minify ) {
			return $html;
		}

		$delay_exclude = array_merge( self::NEVER_DELAY, Settings::lines( 'delay_exclude' ) );
		if ( ! $trackers ) {
			$delay_exclude = array_merge( $delay_exclude, Assets::tracker_patterns() );
			// Pixel plugins that depend on jQuery (e.g. PixelYourSite): jQuery cannot be
			// delayed either, or the pixel would only fire after the first interaction.
			if ( Assets::matches( $html, Assets::jquery_trackers() ) ) {
				$delay_exclude = array_merge( $delay_exclude, Assets::jquery_trackers(), array( '/jquery.min.js', '/jquery.js', 'jquery-migrate', 'jquery-core-js-extra' ) );
			}
		}
		$defer_exclude = Settings::lines( 'defer_exclude' );
		if ( ! Settings::get( 'defer_jquery' ) ) {
			$defer_exclude[] = '/jquery.min.js';
			$defer_exclude[] = '/jquery.js';
			$defer_exclude[] = 'jquery-migrate';
		}
		$deferred_any = false;

		return preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script\s*>#is',
			function ( $m ) use ( $delay, $defer, $minify, $delay_exclude, $defer_exclude, &$deferred_any ) {
				$attrs = Html::attrs( '<script' . $m[1] . '>' );
				$type  = isset( $attrs['type'] ) ? strtolower( trim( (string) $attrs['type'] ) ) : '';
				if ( ! in_array( $type, self::JS_TYPES, true ) ) {
					return $m[0]; // JSON, ld+json, templates...
				}
				if ( isset( $attrs['data-fwp-skip'] ) || isset( $attrs['data-no-optimize'] ) || isset( $attrs['nowprocket'] ) || isset( $attrs['data-pagespeed-no-defer'] ) || ( isset( $attrs['data-cfasync'] ) && 'false' === $attrs['data-cfasync'] ) ) {
					return $m[0];
				}
				$src  = Html::decoded( $attrs, 'src' );
				$code = $m[2];

				if ( $minify && '' !== $src ) {
					$min = $this->minified_url( $src );
					if ( $min ) {
						$attrs['src'] = esc_url( $min );
					}
				}

				$haystack = '' !== $src ? $src : $code;
				if ( '' === $src && '' === trim( $code ) ) {
					return $m[0];
				}

				// 1) Delay until interaction.
				if ( $delay && ! Assets::matches( $haystack, $delay_exclude ) ) {
					$this->has_delayed = true;
					if ( 'module' === $type ) {
						$attrs['data-fwp-type'] = 'module';
					}
					if ( '' !== $src ) {
						$attrs['data-fwp-src'] = $attrs['src'];
						unset( $attrs['src'] );
					}
					$attrs['type'] = 'fwp/delay';
					unset( $attrs['async'], $attrs['defer'] );
					return Html::tag( 'script', $attrs ) . $code . '</script>';
				}

				// 2) Defer.
				if ( ! $defer || 'module' === $type || Assets::matches( $haystack, $defer_exclude ) ) {
					return Html::tag( 'script', $attrs ) . $code . '</script>';
				}
				if ( '' !== $src ) {
					if ( ! isset( $attrs['async'] ) && ! isset( $attrs['defer'] ) ) {
						$attrs['defer'] = true;
					}
					if ( isset( $attrs['defer'] ) ) {
						$deferred_any = true;
					}
					return Html::tag( 'script', $attrs ) . '</script>';
				}
				// Inline script after a deferred script: runs in the same order, also deferred.
				if ( $deferred_any ) {
					$attrs['src']   = 'data:text/javascript;base64,' . base64_encode( $code ); // phpcs:ignore
					$attrs['defer'] = true;
					return Html::tag( 'script', $attrs ) . '</script>';
				}
				return $m[0];
			},
			$html
		);
	}

	/** Creates a minified copy of a local JS file that is not .min.js yet. */
	private function minified_url( $src ) {
		$clean = preg_replace( '/[?#].*$/', '', $src );
		if ( preg_match( '/[.-]min\.js$/i', $clean ) ) {
			return null;
		}
		$path = Assets::url_to_path( $clean );
		if ( ! $path || ! preg_match( '/\.js$/i', $path ) ) {
			return null;
		}
		$size = @filesize( $path ); // phpcs:ignore
		if ( ! $size || $size > 2 * MB_IN_BYTES ) {
			return null;
		}
		$key = 'js-' . md5( $path . '|' . filemtime( $path ) . '|' . $size ) . '.txt';
		$url = Assets::memo_get( $key );
		if ( $url ) {
			return $url;
		}
		$code = file_get_contents( $path ); // phpcs:ignore
		if ( false === $code ) {
			return null;
		}
		$min = Minify::js( $code );
		if ( strlen( $min ) >= strlen( $code ) ) {
			Assets::memo_set( $key, '' );
			return null;
		}
		$url = Assets::write( $min . "\n", 'js' );
		if ( $url ) {
			Assets::memo_set( $key, $url );
		}
		return $url;
	}

	/**
	 * Browser script: loads delayed scripts on the first interaction,
	 * releases the full CSS, lazy loads backgrounds and handles the YouTube thumbnail.
	 */
	public static function loader( array $cfg ) {
		$json = wp_json_encode( $cfg );
		$js   = <<<'JS'
(function(w,d){var c=W_CFG,ev=["keydown","mousedown","mousemove","touchstart","touchmove","wheel","pointerdown"],started=false,done=false,clicked=null;
function each(l,f){Array.prototype.forEach.call(l,f)}
function css(){each(d.querySelectorAll("link[data-fwp-href]"),function(l){l.href=l.getAttribute("data-fwp-href");l.removeAttribute("data-fwp-href")})}
function stop(){ev.forEach(function(e){w.removeEventListener(e,go,{passive:true})})}
function go(){if(started)return;started=true;stop();if(c.css==="interaction")css();if(c.delay)run();}
function run(){var list=[].slice.call(d.querySelectorAll('script[type="fwp/delay"]')),dcl=[],load=[],oD=d.addEventListener,oW=w.addEventListener,onl=w.onload,jq=null,oReady=null,jqLoad=0;
function hook(){var $=w.jQuery;if(!$||!$.fn||jq===$)return;jq=$;oReady=$.fn.ready;$.fn.ready=function(f){dcl.push(function(){f.call(d,$)});return this};try{var e=$._data(w,"events");jqLoad=e&&e.load?e.load.length:0}catch(x){}}
hook();
each(list,function(s){var u=s.getAttribute("data-fwp-src");if(u){var p=d.createElement("link");p.rel="preload";p.as="script";p.href=u;d.head.appendChild(p)}});
d.addEventListener=function(t,f,o){if(t==="DOMContentLoaded"){dcl.push(f)}else{oD.call(d,t,f,o)}};
w.addEventListener=function(t,f,o){if(t==="DOMContentLoaded"){dcl.push(f)}else if(t==="load"){load.push(f)}else{oW.call(w,t,f,o)}};
function next(){hook();var o=list.shift();if(!o){fin();return}var s=d.createElement("script");
each(o.attributes,function(a){if(a.name!=="type"&&a.name!=="data-fwp-src"&&a.name!=="data-fwp-type")s.setAttribute(a.name,a.value)});
var t=o.getAttribute("data-fwp-type");if(t)s.type=t;var u=o.getAttribute("data-fwp-src");
if(u){s.src=u;s.async=false;s.onload=s.onerror=next;o.parentNode.replaceChild(s,o)}else{s.text=o.text;o.parentNode.replaceChild(s,o);next()}}
function fin(){d.addEventListener=oD;w.addEventListener=oW;if(jq)jq.fn.ready=oReady;
dcl.forEach(function(f){try{f.call(d,new Event("DOMContentLoaded"))}catch(x){console.error(x)}});
load.forEach(function(f){try{f.call(w,new Event("load"))}catch(x){console.error(x)}});
if(jq&&jqLoad){try{var e=jq._data(w,"events");if(e&&e.load){e.load.slice(jqLoad).forEach(function(h){h.handler.call(w,jq.Event("load"))})}}catch(x){console.error(x)}}
if(w.onload&&w.onload!==onl){try{w.onload(new Event("load"))}catch(x){console.error(x)}}
done=true;if(clicked){var el=clicked;clicked=null;setTimeout(function(){el.click()},60)}}
next()}
if(c.delay){d.addEventListener("click",function(e){if(done)return;var a=e.target.closest?e.target.closest("a[href]"):null;if(a&&a.getAttribute("href").charAt(0)!=="#")return;e.preventDefault();e.stopPropagation();clicked=e.target;go()},true)}
if(c.delay||c.css==="interaction"){ev.forEach(function(e){w.addEventListener(e,go,{passive:true})});if(c.timeout>0)setTimeout(go,c.timeout*1000)}
if(c.css==="load"){w.addEventListener("load",function(){setTimeout(css,100)})}
function bg(){var els=d.querySelectorAll(".fwp-lazy-bg[data-fwp-bg]");if(!els.length)return;
var show=function(el){el.style.backgroundImage='url("'+el.getAttribute("data-fwp-bg")+'")';el.removeAttribute("data-fwp-bg")};
if(!("IntersectionObserver" in w)){each(els,show);return}
var io=new IntersectionObserver(function(en){en.forEach(function(x){if(x.isIntersecting){show(x.target);io.unobserve(x.target)}})},{rootMargin:"300px"});each(els,function(el){io.observe(el)})}
function yt(){d.addEventListener("click",function(e){var b=e.target.closest?e.target.closest(".fwp-yt"):null;if(!b)return;e.preventDefault();e.stopPropagation();
var f=d.createElement("iframe");f.src=b.getAttribute("data-fwp-yt");f.title=b.getAttribute("data-title")||"";f.allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture";f.allowFullscreen=true;f.setAttribute("frameborder","0");
b.innerHTML="";b.appendChild(f);b.classList.add("fwp-yt-on")},true)}
if(d.readyState==="loading"){d.addEventListener("DOMContentLoaded",bg)}else{bg()}yt();
})(window,document);
JS;
		return '<script id="fwp-loader" data-fwp-skip>' . str_replace( 'W_CFG', $json, $js ) . '</script>';
	}

	/** Small CSS used by the YouTube thumbnail, animated elements and images that got dimensions. */
	public static function inline_css( $youtube, $animations, $dimensions = false ) {
		$css = '';
		if ( $dimensions ) {
			// Zero specificity: any theme rule that sets a height still wins; the width/height ratio is kept.
			$css .= ':where(img[data-fwp-dim]){height:auto}';
		}
		if ( $youtube ) {
			$css .= '.fwp-yt{position:relative;display:block;width:100%;aspect-ratio:16/9;background:#000;cursor:pointer;overflow:hidden}'
				. '.fwp-yt img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
				. '.fwp-yt iframe{position:absolute;inset:0;width:100%;height:100%;border:0}'
				. '.fwp-yt-play{position:absolute;left:50%;top:50%;width:68px;height:48px;margin:-24px 0 0 -34px;border:0;border-radius:12px;background:#f00;opacity:.9;cursor:pointer}'
				. '.fwp-yt-play:before{content:"";position:absolute;left:27px;top:14px;border-style:solid;border-width:10px 0 10px 18px;border-color:transparent transparent transparent #fff}'
				. '.fwp-yt:hover .fwp-yt-play{opacity:1}';
		}
		if ( $animations ) {
			$css .= '.elementor-invisible{visibility:visible!important}';
		}
		return '' === $css ? '' : '<style id="fwp-inline-css">' . $css . '</style>';
	}
}
