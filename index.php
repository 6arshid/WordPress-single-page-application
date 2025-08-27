<?php
/**
 * Plugin Name: WordPress single page application
 * Description: A lightweight, theme‑agnostic SPA layer for WordPress with PJAX‑style navigation, live search shortcode, and AJAX comments — all in one file.
 * Version: 1.0.0
 * Author: mrlast, hassantafreshi, mostafas1990
 * License: MIT
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AP_Lite {
	const VERSION = '1.0.0';
	private static $instance = null;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'init', [ $this, 'register_rest' ] );
		add_shortcode( 'ap_live_search', [ $this, 'shortcode_live_search' ] );
		// Allow themes/plugins to override the SPA root selector
		add_filter( 'ap_lite_root_selector', function( $sel ) { return '#ap-root'; } );
	}

	/**
	 * Enqueue a tiny runtime and localize configuration
	 */
	public function assets() {
		// A tiny empty handle to attach inline code to
		$handle = 'ap-lite';
		wp_register_script( $handle, false, [], self::VERSION, true );

		$cfg = [
			'rest'      => esc_url_raw( rest_url( 'ap/v1' ) ),
			'wpSearch'  => esc_url_raw( rest_url( 'wp/v2/search' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'site'      => home_url( '/' ),
			'rootSel'   => apply_filters( 'ap_lite_root_selector', '#ap-root' ),
			'fallbackSels' => [ 'main', '#content', '#primary', '.site-content', '.content-area', '.entry-content' ],
		];

		wp_localize_script( $handle, 'AP_LITE', $cfg );
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, $this->runtime_js(), 'after' );

		// minimal styles for progress + search results
		$css = '/* AjaxPress Lite styles */\n#ap-lite-progress{position:fixed;left:0;top:0;height:3px;width:0;z-index:99999;background:currentColor;opacity:.7;transition:width .25s ease,opacity .25s ease} .ap-live-wrap{position:relative} .ap-live-results{position:absolute;left:0;right:0;top:calc(100% + 6px);max-height:360px;overflow:auto;border:1px solid rgba(0,0,0,.08);background:#fff;box-shadow:0 6px 18px rgba(0,0,0,.08);border-radius:8px;padding:8px} .ap-live-item{display:flex;gap:10px;align-items:flex-start;padding:8px;border-radius:6px;text-decoration:none} .ap-live-item:hover{background:rgba(0,0,0,.04)} .ap-live-thumb{flex:0 0 48px;height:48px;object-fit:cover;border-radius:6px;background:#f2f2f2} .ap-live-title{font-weight:600;margin:0 0 4px} .ap-live-excerpt{margin:0;font-size:13px;opacity:.85} .ap-live-empty{padding:8px;color:#666;font-size:13px} .ap-live-results, .ap-live-results *{list-style:none;margin:0;padding:0} ';
		wp_register_style( 'ap-lite-style', false, [], self::VERSION );
		wp_enqueue_style( 'ap-lite-style' );
		wp_add_inline_style( 'ap-lite-style', $css );
	}

	/**
	 * REST routes for search (rich) and comments
	 */
	public function register_rest() {
		register_rest_route( 'ap/v1', '/search', [
			'methods'  => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'args' => [ 'q' => [ 'type' => 'string', 'required' => true ], 'pt' => [ 'type' => 'array', 'items' => ['type'=>'string'], 'required'=>false ], 'limit' => [ 'type' => 'integer', 'default' => 10 ] ],
			'callback' => function( WP_REST_Request $req ) {
				$q = sanitize_text_field( $req->get_param('q') );
				if ( $q === '' ) return rest_ensure_response( [] );
				$pt = $req->get_param('pt');
				$limit = max(1, min(20, (int)$req->get_param('limit')) );

				$args = [
					'post_type' => ( $pt && is_array($pt) ) ? array_map('sanitize_key',$pt) : ['post','page'],
					's' => $q,
					'posts_per_page' => $limit,
					'ignore_sticky_posts' => true,
					'post_status' => 'publish'
				];
				$qry = new WP_Query( $args );
				$items = [];
				while ( $qry->have_posts() ) { $qry->the_post();
					$items[] = [
						'id' => get_the_ID(),
						'type' => get_post_type(),
						'title' => html_entity_decode( get_the_title() ),
						'url' => get_permalink(),
						'excerpt' => wp_strip_all_tags( get_the_excerpt(), true ),
						'thumb' => get_the_post_thumbnail_url( get_the_ID(), 'thumbnail' ),
					];
				}
				wp_reset_postdata();
				return rest_ensure_response( $items );
			}
		] );

		register_rest_route( 'ap/v1', '/comment', [
			'methods'  => WP_REST_Server::CREATABLE,
			'permission_callback' => function( WP_REST_Request $req ) {
				$nonce = $req->get_header( 'X-WP-Nonce' );
				return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
			},
			'args' => [
				'post' => [ 'type' => 'integer', 'required' => true ],
				'content' => [ 'type' => 'string', 'required' => true ],
				'parent' => [ 'type' => 'integer', 'required' => false ],
				'author_name' => [ 'type' => 'string', 'required' => false ],
				'author_email' => [ 'type' => 'string', 'required' => false ],
				'author_url' => [ 'type' => 'string', 'required' => false ],
			],
			'callback' => function( WP_REST_Request $req ) {
				$post_id = (int) $req->get_param('post');
				if ( ! get_post( $post_id ) ) return new WP_Error( 'ap_invalid_post', 'Invalid post', [ 'status' => 400 ] );

				$commentdata = [
					'comment_post_ID'      => $post_id,
					'comment_parent'       => (int) $req->get_param('parent'),
					'comment_content'      => wp_kses_post( $req->get_param('content') ),
					'comment_author'       => sanitize_text_field( $req->get_param('author_name') ),
					'comment_author_email' => sanitize_email( $req->get_param('author_email') ),
					'comment_author_url'   => esc_url_raw( $req->get_param('author_url') ),
					'user_id'              => get_current_user_id(),
					'comment_author_IP'    => $_SERVER['REMOTE_ADDR'] ?? '',
					'comment_agent'        => $_SERVER['HTTP_USER_AGENT'] ?? '',
				];

				// Respect discussion settings
				if ( get_option( 'comment_registration' ) && ! is_user_logged_in() ) {
					return new WP_Error( 'ap_login_required', __( 'You must be logged in to comment.' ), [ 'status' => 401 ] );
				}
				if ( ! comments_open( $post_id ) ) {
					return new WP_Error( 'ap_closed', __( 'Comments are closed.' ), [ 'status' => 403 ] );
				}

				$cid = wp_new_comment( $commentdata, true );
				if ( is_wp_error( $cid ) ) return $cid;
				$c = get_comment( $cid );
				return rest_ensure_response( [
					'id' => $c->comment_ID,
					'approved' => (int) $c->comment_approved,
					'content' => apply_filters( 'comment_text', $c->comment_content, $c ),
					'author' => get_comment_author( $c ),
					'html' => get_comment_text( $c )
				] );
			}
		] );
	}

	/**
	 * Shortcode: [ap_live_search post_types="post,page" limit="8" placeholder="جستجو..."]
	 */
	public function shortcode_live_search( $atts ) {
		$atts = shortcode_atts( [
			'post_types' => 'post,page',
			'limit' => 8,
			'placeholder' => __( 'Type to search…', 'ap-lite' ),
		], $atts, 'ap_live_search' );

		$post_types = implode( ',', array_map( 'sanitize_key', array_filter( array_map( 'trim', explode( ',', $atts['post_types'] ) ) ) ) );
		$limit = max(1, min(20, (int) $atts['limit']));
		$ph = esc_attr( $atts['placeholder'] );

		$root_id = 'ap-live-' . wp_generate_password( 8, false );
		$pt_data = esc_attr( $post_types );

		ob_start(); ?>
		<div class="ap-live-wrap" id="<?php echo esc_attr( $root_id ); ?>" data-limit="<?php echo esc_attr( $limit ); ?>" data-post-types="<?php echo $pt_data; ?>">
			<input type="search" class="ap-live-input" placeholder="<?php echo $ph; ?>" autocomplete="off" />
			<div class="ap-live-results" hidden></div>
		</div>
		<?php return ob_get_clean();
	}

	/**
	 * Tiny SPA runtime (no deps)
	 */
	private function runtime_js() {
		return <<<JS
(function(){
	'use strict';
	var cfg = window.AP_LITE || {};
	var site = cfg.site || location.origin + '/';
	var ROOT = cfg.rootSel || '#ap-root';
	var FALLBACKS = (cfg.fallbackSels || []);
	var fetching = false;
	var progressEl;

	function qs(sel, ctx){ return (ctx||document).querySelector(sel); }
	function qsa(sel, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(sel)); }
	function sameOrigin(url){ try{ var u=new URL(url, location.href); return u.origin===location.origin; }catch(e){ return false; } }
	function isHashJump(a){ return a.hash && (a.pathname===location.pathname) && a.hash!==''; }

	function ensureProgress(){
		if(progressEl) return progressEl; progressEl = document.createElement('div'); progressEl.id='ap-lite-progress'; document.body.appendChild(progressEl); return progressEl;
	}
	function setProgress(v){ ensureProgress().style.width = (v*100)+'%'; if(v>=1){ setTimeout(function(){ ensureProgress().style.opacity=0; setTimeout(function(){ ensureProgress().style.width='0'; ensureProgress().style.opacity=0.7; }, 250); }, 150);} }

	function findRoot(doc){
		var el = doc.querySelector(ROOT);
		if(!el){
			for(var i=0;i<FALLBACKS.length;i++){ var c = doc.querySelector(FALLBACKS[i]); if(c){ el = c; break; } }
		}
		if(!el) return null;
		// avoid using <li> as root (prevents stray bullets on some themes)
		if(el.tagName && el.tagName.toLowerCase()==='li'){ el = el.parentElement || el; }
		return el;
	}
	function updateTitle(doc){ var t=doc.querySelector('title'); if(t) document.title = t.textContent; }
	function replaceContent(newDoc){
		var src = findRoot(newDoc); if(!src) return false; var dst = findRoot(document); if(!dst) return false; dst.innerHTML = src.innerHTML; return true;
	}

	function pjax(url, opts){
		if(fetching) return; fetching=true; setProgress(0.2);
		fetch(url, { headers: { 'X-Requested-With':'AP-Lite' } })
		.then(function(r){ setProgress(0.6); return r.text(); })
		.then(function(html){
			var doc = new DOMParser().parseFromString(html,'text/html');
			updateTitle(doc);
			var ok = replaceContent(doc);
			setProgress(1);
			if(ok){ window.history.pushState({url:url}, '', url); window.scrollTo({top:0,behavior:'smooth'}); bindDynamic(); }
			else { location.href = url; }
		})
		.catch(function(){ location.href = url; })
		.finally(function(){ fetching=false; });
	}

	function onClick(e){
		var a = e.target.closest('a');
		if(!a) return;
		if(a.hasAttribute('download') || a.target==='_blank' || a.classList.contains('no-ajax')) return;
		var url = a.href; if(!url || !sameOrigin(url)) return;
		if(isHashJump(a)) return; // allow in-page anchors
		e.preventDefault();
		pjax(url);
	}

	// LIVE SEARCH
	function debounce(fn, ms){ var t; return function(){ var a=arguments; clearTimeout(t); t=setTimeout(function(){ fn.apply(null,a); }, ms); }; }
	function initLiveSearch(root){
		var wrap = root || document; var inputs = qsa('.ap-live-wrap', wrap);
		inputs.forEach(function(box){
			var input = qs('.ap-live-input', box); if(!input) return;
			var results = qs('.ap-live-results', box); if(!results) return;
			var limit = parseInt(box.getAttribute('data-limit')||'8',10);
			var postTypes = (box.getAttribute('data-post-types')||'').split(',').filter(Boolean);
			var search = debounce(function(){
				var q = (input.value||'').trim();
				if(q===''){ results.innerHTML=''; results.hidden=true; return; }
				var url = cfg.rest + '/search?q=' + encodeURIComponent(q) + '&limit=' + limit + (postTypes.length?('&pt[]=' + postTypes.join('&pt[]=')):'');
				fetch(url).then(function(r){ return r.json(); }).then(function(items){
					if(!Array.isArray(items)) items=[]; if(items.length===0){ results.innerHTML='<div class="ap-live-empty">No results</div>'; results.hidden=false; return; }
					results.innerHTML = items.map(function(it){
						var img = it.thumb ? '<img class="ap-live-thumb" loading="lazy" src="'+it.thumb+'" alt="">' : '<div class="ap-live-thumb"></div>';
						var ex = it.excerpt ? '<p class="ap-live-excerpt">'+escapeHtml(it.excerpt)+'</p>' : '';
						return '<a class="ap-live-item" href="'+it.url+'">'+ img +'<div><div class="ap-live-title">'+escapeHtml(it.title)+'</div>'+ex+'</div></a>';
					}).join('');
					results.hidden=false;
				}).catch(function(){ results.hidden=true; });
			}, 220);
			input.addEventListener('input', search);
			results.addEventListener('click', function(e){ var a=e.target.closest('a'); if(a){ e.preventDefault(); pjax(a.href); results.hidden=true; }});
		});
	}
	function escapeHtml(s){ var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

	// AJAX COMMENTS (progressive enhancement)
	function initAjaxComments(root){
		var wrap = root || document;
		var form = qs('form#commentform', wrap);
		if(!form) return;
		form.addEventListener('submit', function(e){
			e.preventDefault();
			var fd = new FormData(form);
			var payload = {
				post: parseInt(fd.get('comment_post_ID')||'0',10),
				parent: parseInt(fd.get('comment_parent')||'0',10) || 0,
				content: fd.get('comment')||'',
				author_name: fd.get('author')||'',
				author_email: fd.get('email')||'',
				author_url: fd.get('url')||''
			};
			setProgress(0.2);
			fetch(cfg.rest + '/comment', { method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce}, body: JSON.stringify(payload) })
			.then(function(r){ return r.json(); })
			.then(function(res){
				setProgress(1);
				// After posting, refresh only the comments block
				fetch(location.href).then(function(r){ return r.text(); }).then(function(html){
					var doc = new DOMParser().parseFromString(html,'text/html');
					var newComments = doc.querySelector('#comments');
					var curComments = document.querySelector('#comments');
					if(newComments && curComments){ curComments.innerHTML = newComments.innerHTML; }
					// clear textarea
					var ta = form.querySelector('textarea[name=comment]'); if(ta) ta.value='';
					var note = document.createElement('div'); note.textContent = (res && res.approved==1)? 'نظر شما ثبت شد.' : 'نظر شما ارسال شد و بعد از تأیید نمایش داده می‌شود.'; note.style.margin='6px 0';
					form.parentNode.insertBefore(note, form.nextSibling);
				});
			}).catch(function(){ setProgress(1); });
		});
	}

	function bindDynamic(root){ initLiveSearch(root); initAjaxComments(root); }

	// Initial binds
	document.addEventListener('click', onClick);
	window.addEventListener('popstate', function(e){ if(e.state && e.state.url){ pjax(e.state.url); } });
	bindDynamic(document);
})();
JS;
	}
}

AP_Lite::instance();

