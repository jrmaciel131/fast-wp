<?php
/**
 * Tela de opções, avisos, barra de administração e ações dos botões.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGE = 'fast-wp';

	/** Plugins que fazem o mesmo trabalho e entram em conflito. */
	const CONFLICTS = array(
		'wp-fastest-cache/wpFastestCache.php'           => 'WP Fastest Cache',
		'wp-rocket/wp-rocket.php'                       => 'WP Rocket',
		'litespeed-cache/litespeed-cache.php'           => 'LiteSpeed Cache',
		'w3-total-cache/w3-total-cache.php'             => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'                   => 'WP Super Cache',
		'autoptimize/autoptimize.php'                   => 'Autoptimize',
		'perfmatters/perfmatters.php'                   => 'Perfmatters',
		'wp-optimize/wp-optimize.php'                   => 'WP-Optimize',
		'sg-cachepress/sg-cachepress.php'               => 'Speed Optimizer (SiteGround)',
		'breeze/breeze.php'                             => 'Breeze',
		'hummingbird-performance/wp-hummingbird.php'    => 'Hummingbird',
		'cache-enabler/cache-enabler.php'               => 'Cache Enabler',
		'flying-press/flying-press.php'                 => 'FlyingPress',
		'easy-optimizer/easy-optimizer.php'             => 'Easy Optimizer',
		'speedycache/speedycache.php'                   => 'SpeedyCache',
		'nitropack/main.php'                            => 'NitroPack',
		'jetpack-boost/jetpack-boost.php'               => 'Jetpack Boost',
		'swift-performance-lite/performance.php'        => 'Swift Performance',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_fast_wp', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( FAST_WP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'Fast WP', 'Fast WP', 'manage_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-performance', 81 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Configurações</a>' );
		return $links;
	}

	/** URL de uma ação (botão) com nonce. */
	public static function action_url( $do, array $extra = array() ) {
		$args = array_merge(
			array(
				'action' => 'fast_wp',
				'do'     => $do,
			),
			$extra
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'fast_wp_' . $do );
	}

	public static function conflicts() {
		$active = (array) get_option( 'active_plugins', array() );
		$found  = array();
		foreach ( self::CONFLICTS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/* ---------------------------------------------------------------------
	 * Ações
	 * ------------------------------------------------------------------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sem permissão.' );
		}
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		check_admin_referer( 'fast_wp_' . $do );

		$msg  = '';
		$type = 'success';
		switch ( $do ) {
			case 'save':
				$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
				$in  = isset( $_POST['fwp'] ) && is_array( $_POST['fwp'] ) ? $_POST['fwp'] : array(); // phpcs:ignore -- limpo em sanitize_tab.
				Settings::update( Settings::sanitize_tab( $tab, $in ) );
				Plugin::apply_settings();
				$msg = 'Opções salvas e cache limpo.';
				break;
			case 'preset':
				$name    = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : '';
				$presets = Settings::presets();
				if ( isset( $presets[ $name ] ) ) {
					Settings::update( $presets[ $name ]['values'] );
					Plugin::apply_settings();
					$msg = 'Predefinição "' . $presets[ $name ]['label'] . '" aplicada.';
				}
				break;
			case 'test_mode':
				$on = ! empty( $_GET['on'] );
				Settings::update( array( 'test_mode' => $on ? 1 : 0 ) );
				Plugin::apply_settings();
				$msg = $on ? 'Modo de teste ligado: visitantes não recebem as otimizações.' : 'Modo de teste desligado: as otimizações estão valendo para os visitantes.';
				break;
			case 'safe_mode_off':
				Settings::update( array( 'safe_mode' => 0 ) );
				Plugin::apply_settings();
				$msg = 'Modo de segurança desligado.';
				break;
			case 'purge':
				Cache::purge_all();
				$msg = 'Cache limpo.';
				break;
			case 'purge_everything':
				Cache::purge_everything();
				$msg = 'Cache e arquivos otimizados apagados.';
				break;
			case 'purge_url':
				$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
				if ( $url ) {
					Cache::purge_url( $url );
					$msg = 'Cache desta página limpo.';
				}
				break;
			case 'preload':
				Cache::schedule_preload( 1 );
				spawn_cron();
				$msg = 'Pré-carregamento iniciado em segundo plano.';
				break;
			case 'self_test':
				$r    = Self_Test::run();
				$msg  = $r['ok'] ? 'Autoteste OK: o site respondeu normalmente.' : 'O autoteste encontrou um problema. Veja os detalhes no Painel.';
				$type = $r['ok'] ? 'success' : 'error';
				break;
			case 'db_clean':
				$done = Database::clean();
				$msg  = 'Banco limpo: ' . array_sum( $done ) . ' itens removidos e tabelas otimizadas.';
				break;
			case 'webp_bulk':
				Webp::start_bulk();
				spawn_cron();
				$msg = 'Conversão das imagens para WebP iniciada em segundo plano.';
				break;
			case 'dropin_force':
				Cache::install_dropin( true );
				$msg = 'advanced-cache.php substituído pelo do Fast WP.';
				break;
			case 'check_update':
				$rel = Updater::latest( true );
				delete_site_transient( 'update_plugins' );
				$msg = $rel ? 'Versão mais recente no GitHub: ' . $rel['version'] . '.' : 'Não foi possível consultar o GitHub (confira o repositório na aba Avançado).';
				break;
			case 'export':
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=fast-wp-config.json' );
				$data = Settings::all();
				unset( $data['safe_mode'] );
				echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore
				exit;
			case 'import':
				$raw  = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore
				$data = json_decode( (string) $raw, true );
				if ( is_array( $data ) ) {
					$clean = array();
					foreach ( array_keys( Settings::fields() ) as $tab ) {
						$clean = array_merge( $clean, Settings::sanitize_tab( $tab, self::slash_deep( $data ) ) );
					}
					$clean['test_mode'] = 1; // Importou: começa em modo de teste por segurança.
					Settings::update( $clean );
					Plugin::apply_settings();
					$msg = 'Configurações importadas. O modo de teste foi ligado: teste antes de liberar para os visitantes.';
				} else {
					$msg  = 'O texto colado não é uma configuração válida do Fast WP.';
					$type = 'error';
				}
				break;
		}

		$back = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::PAGE );
		}
		if ( $msg ) {
			set_transient( 'fast_wp_notice_' . get_current_user_id(), array( $type, $msg ), 60 );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/** sanitize_tab espera dados com barras (como vêm do $_POST). */
	private static function slash_deep( array $data ) {
		return wp_slash( $data );
	}

	/* ---------------------------------------------------------------------
	 * Avisos
	 * ------------------------------------------------------------------- */

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = get_transient( 'fast_wp_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'fast_wp_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p><strong>Fast WP:</strong> %s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}

		$s = Settings::all();
		if ( ! empty( $s['safe_mode'] ) ) {
			$test = get_option( Self_Test::OPTION );
			printf(
				'<div class="notice notice-error"><p><strong>Fast WP em modo de segurança:</strong> o autoteste encontrou um problema depois de uma atualização e as otimizações foram desligadas (o cache continua). %s <a href="%s">Rodar o autoteste de novo</a> · <a href="%s">Desligar o modo de segurança</a></p></div>',
				$test && ! empty( $test['checks'] ) ? esc_html( self::test_summary( $test ) ) : '',
				esc_url( self::action_url( 'self_test' ) ),
				esc_url( self::action_url( 'safe_mode_off' ) )
			);
		} elseif ( ! empty( $s['test_mode'] ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Fast WP em modo de teste:</strong> visitantes ainda não recebem as otimizações. Veja o resultado em <a href="%s" target="_blank">%s</a> ou <a href="%s" target="_blank">teste no PageSpeed</a>. Quando estiver tudo certo: <a href="%s"><strong>liberar para os visitantes</strong></a>.</p></div>',
				esc_url( add_query_arg( 'fwp_test', 1, home_url( '/' ) ) ),
				esc_html( add_query_arg( 'fwp_test', 1, home_url( '/' ) ) ),
				esc_url( self::pagespeed_url( home_url( '/' ) ) ),
				esc_url( self::action_url( 'test_mode', array( 'on' => 0 ) ) )
			);
		}

		$conflicts = self::conflicts();
		if ( $conflicts ) {
			printf( '<div class="notice notice-error"><p><strong>Fast WP:</strong> desative %s. Dois plugins de cache/otimização ao mesmo tempo causam conflito.</p></div>', esc_html( implode( ', ', $conflicts ) ) );
		}
		if ( ! empty( $s['cache_enabled'] ) && Cache::foreign_dropin() ) {
			printf( '<div class="notice notice-warning"><p><strong>Fast WP:</strong> existe um arquivo advanced-cache.php de outro plugin. Depois de desativar o outro plugin de cache, <a href="%s">use o do Fast WP</a>.</p></div>', esc_url( self::action_url( 'dropin_force' ) ) );
		}
		if ( ! empty( $s['cache_enabled'] ) && ! Cache::wp_cache_active() && ! Cache::foreign_dropin() ) {
			echo '<div class="notice notice-warning"><p><strong>Fast WP:</strong> não consegui ativar o cache no wp-config.php (arquivo sem permissão de escrita). Adicione esta linha logo depois de <code>&lt;?php</code>: <code>define( \'WP_CACHE\', true );</code></p></div>';
		}
		$err = get_option( Optimizer::ERROR_OPTION );
		if ( $err && ! empty( $err['time'] ) && $err['time'] > time() - DAY_IN_SECONDS && isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore
			printf( '<div class="notice notice-info"><p><strong>Fast WP:</strong> uma otimização falhou em %s e a página foi entregue sem otimizar (o site não quebrou). Detalhe: %s</p></div>', esc_html( $err['url'] ), esc_html( $err['message'] ) );
		}
	}

	private static function test_summary( array $test ) {
		$out = array();
		foreach ( $test['checks'] as $name => $c ) {
			$out[] = ucfirst( $name ) . ': ' . $c['message'];
		}
		return implode( ' | ', $out );
	}

	public static function pagespeed_url( $url ) {
		if ( Settings::get( 'test_mode' ) ) {
			$url = add_query_arg( 'fwp_test', 1, $url );
		}
		return 'https://pagespeed.web.dev/report?url=' . rawurlencode( $url ) . '&form_factor=mobile';
	}

	/* ---------------------------------------------------------------------
	 * Barra de administração
	 * ------------------------------------------------------------------- */

	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = Settings::all();
		$title = 'Fast WP' . ( ! empty( $s['safe_mode'] ) ? ' (segurança)' : ( ! empty( $s['test_mode'] ) ? ' (teste)' : '' ) );
		$bar->add_node(
			array(
				'id'    => 'fast-wp',
				'title' => $title,
				'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'fast-wp',
				'id'     => 'fast-wp-purge',
				'title'  => 'Limpar todo o cache',
				'href'   => self::action_url( 'purge' ),
			)
		);
		if ( ! is_admin() ) {
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$current = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
			$current = remove_query_arg( array( 'fwp_test', 'fwp_off' ), $current );
			$bar->add_node(
				array(
					'parent' => 'fast-wp',
					'id'     => 'fast-wp-purge-url',
					'title'  => 'Limpar cache desta página',
					'href'   => self::action_url( 'purge_url', array( 'url' => rawurlencode( $current ), 'back' => rawurlencode( $current ) ) ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'fast-wp',
					'id'     => 'fast-wp-view-opt',
					'title'  => 'Ver esta página otimizada',
					'href'   => add_query_arg( 'fwp_test', 1, $current ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'fast-wp',
					'id'     => 'fast-wp-view-off',
					'title'  => 'Ver esta página sem otimização',
					'href'   => add_query_arg( 'fwp_off', 1, $current ),
				)
			);
			$bar->add_node(
				array(
					'parent' => 'fast-wp',
					'id'     => 'fast-wp-psi',
					'title'  => 'Testar esta página no PageSpeed',
					'href'   => self::pagespeed_url( $current ),
					'meta'   => array( 'target' => '_blank' ),
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Tela
	 * ------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$fields = Settings::fields();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'painel'; // phpcs:ignore
		if ( 'painel' !== $tab && ! isset( $fields[ $tab ] ) ) {
			$tab = 'painel';
		}
		echo '<div class="wrap fwp-wrap"><h1>Fast WP <small style="font-size:13px;color:#666">versão ' . esc_html( FAST_WP_VERSION ) . '</small></h1>';
		self::styles();
		echo '<nav class="nav-tab-wrapper">';
		$tabs = array_merge( array( 'painel' => 'Painel' ), wp_list_pluck( $fields, 'title' ) );
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'painel' === $tab ) {
			self::render_dashboard();
		} else {
			self::render_form( $tab, $fields[ $tab ] );
		}
		echo '</div>';
	}

	private static function styles() {
		echo '<style>.fwp-wrap .fwp-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:980px}.fwp-wrap .fwp-box h2{margin-top:0}.fwp-wrap .fwp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px 24px}.fwp-ok{color:#008a20;font-weight:600}.fwp-bad{color:#d63638;font-weight:600}.fwp-warn{color:#b26200;font-weight:600}.fwp-wrap .form-table th{width:300px}.fwp-wrap .description{max-width:640px}.fwp-btns .button{margin:0 6px 6px 0}</style>';
	}

	private static function status_line( $label, $state, $text ) {
		$class = 'ok' === $state ? 'fwp-ok' : ( 'bad' === $state ? 'fwp-bad' : 'fwp-warn' );
		printf( '<div>%s: <span class="%s">%s</span></div>', esc_html( $label ), esc_attr( $class ), esc_html( $text ) );
	}

	private static function render_dashboard() {
		$s = Settings::all();

		echo '<div class="fwp-box"><h2>Situação</h2><div class="fwp-grid">';
		if ( ! empty( $s['safe_mode'] ) ) {
			self::status_line( 'Modo', 'bad', 'segurança (otimizações desligadas)' );
		} elseif ( ! empty( $s['test_mode'] ) ) {
			self::status_line( 'Modo', 'warn', 'teste (só com ?fwp_test=1)' );
		} else {
			self::status_line( 'Modo', 'ok', 'ativo para os visitantes' );
		}
		self::status_line( 'Cache de página', ! empty( $s['cache_enabled'] ) ? 'ok' : 'warn', ! empty( $s['cache_enabled'] ) ? 'ligado' : 'desligado' );
		self::status_line( 'WP_CACHE no wp-config', Cache::wp_cache_active() ? 'ok' : 'bad', Cache::wp_cache_active() ? 'ativo' : 'inativo' );
		self::status_line( 'advanced-cache.php', Cache::foreign_dropin() ? 'bad' : ( is_file( Cache::dropin_path() ) ? 'ok' : 'warn' ), Cache::foreign_dropin() ? 'de outro plugin' : ( is_file( Cache::dropin_path() ) ? 'do Fast WP' : 'ausente' ) );
		self::status_line( 'Servidor', 'ok', Htaccess::is_apache() ? 'Apache/LiteSpeed (cache servido pelo .htaccess)' : 'outro (cache servido pelo PHP)' );
		self::status_line( 'Páginas em cache', 'ok', (string) self::count_cached() );
		self::status_line( 'WebP no servidor', Webp::supported() ? 'ok' : 'warn', Webp::supported() ? 'suportado' : 'não suportado' );
		$conf = self::conflicts();
		self::status_line( 'Conflitos', $conf ? 'bad' : 'ok', $conf ? implode( ', ', $conf ) : 'nenhum' );
		echo '</div></div>';

		echo '<div class="fwp-box fwp-btns"><h2>Ações</h2>';
		printf( '<a class="button button-primary" href="%s">Limpar todo o cache</a>', esc_url( self::action_url( 'purge' ) ) );
		printf( '<a class="button" href="%s">Pré-carregar o cache agora</a>', esc_url( self::action_url( 'preload' ) ) );
		printf( '<a class="button" href="%s">Rodar autoteste</a>', esc_url( self::action_url( 'self_test' ) ) );
		printf( '<a class="button" target="_blank" href="%s">Testar no PageSpeed (celular)</a>', esc_url( self::pagespeed_url( home_url( '/' ) ) ) );
		if ( ! empty( $s['test_mode'] ) ) {
			printf( '<a class="button button-primary" href="%s">Liberar otimizações para os visitantes</a>', esc_url( self::action_url( 'test_mode', array( 'on' => 0 ) ) ) );
		} else {
			printf( '<a class="button" href="%s">Voltar para o modo de teste</a>', esc_url( self::action_url( 'test_mode', array( 'on' => 1 ) ) ) );
		}
		echo '<p class="description">No modo de teste, só quem abre o site com <code>?fwp_test=1</code> no fim do endereço vê a versão otimizada. O link do PageSpeed acima já inclui isso. Para comparar, <code>?fwp_off=1</code> mostra a página sem nenhuma otimização.</p>';
		echo '</div>';

		echo '<div class="fwp-box fwp-btns"><h2>Predefinições</h2>';
		foreach ( Settings::presets() as $key => $p ) {
			printf( '<a class="button" href="%s" onclick="return confirm(\'Aplicar a predefinição %s?\')">%s</a>', esc_url( self::action_url( 'preset', array( 'preset' => $key ) ) ), esc_js( $p['label'] ), esc_html( $p['label'] ) );
		}
		echo '<p class="description"><strong>Seguro:</strong> cache, minificação, lazy load e fontes, sem atrasar JS nem remover CSS. <strong>Recomendado:</strong> tudo, menos atrasar pixels de anúncio. <strong>Máximo:</strong> tudo, inclusive pixels e GTM (o rastreio só começa depois da primeira interação).</p>';
		echo '</div>';

		$test = get_option( Self_Test::OPTION );
		if ( $test && ! empty( $test['checks'] ) ) {
			echo '<div class="fwp-box"><h2>Último autoteste</h2>';
			printf( '<p>%s em %s</p><ul>', $test['ok'] ? '<span class="fwp-ok">Tudo certo</span>' : '<span class="fwp-bad">Problema encontrado</span>', esc_html( wp_date( 'd/m/Y H:i', $test['time'] ) ) );
			foreach ( $test['checks'] as $name => $c ) {
				printf( '<li><strong>%s:</strong> %s</li>', esc_html( ucfirst( $name ) ), esc_html( $c['message'] ) );
			}
			echo '</ul></div>';
		}

		echo '<div class="fwp-box"><h2>Emergência</h2><p>Se o site quebrar e você não conseguir entrar no painel, adicione no <code>wp-config.php</code>:</p><p><code>define( \'FAST_WP_DISABLE\', true );</code></p><p>Isso desliga o cache e todas as otimizações na hora. Desativar o plugin também desfaz tudo: remove as regras do .htaccess, o advanced-cache.php e a linha do wp-config.php.</p></div>';
	}

	private static function count_cached() {
		$dir = FAST_WP_CACHE_DIR . '/pages';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$n  = 0;
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && '.html' === substr( $f->getFilename(), -5 ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function render_form( $tab, array $def ) {
		$s = Settings::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'fast_wp_save' );
		echo '<input type="hidden" name="action" value="fast_wp"><input type="hidden" name="do" value="save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<div class="fwp-box"><table class="form-table" role="presentation">';
		foreach ( $def['fields'] as $key => $f ) {
			list( $type, , $label ) = $f;
			$desc = isset( $f[3] ) ? $f[3] : '';
			$val  = isset( $s[ $key ] ) ? $s[ $key ] : $f[1];
			$name = 'fwp[' . $key . ']';
			echo '<tr><th scope="row"><label for="fwp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			switch ( $type ) {
				case 'checkbox':
					printf( '<label><input type="checkbox" id="fwp-%1$s" name="%2$s" value="1"%3$s> Ativar</label>', esc_attr( $key ), esc_attr( $name ), checked( ! empty( $val ), true, false ) );
					break;
				case 'number':
					printf( '<input type="number" min="0" class="small-text" id="fwp-%1$s" name="%2$s" value="%3$s">', esc_attr( $key ), esc_attr( $name ), esc_attr( (string) $val ) );
					break;
				case 'select':
					printf( '<select id="fwp-%1$s" name="%2$s">', esc_attr( $key ), esc_attr( $name ) );
					foreach ( $f[4] as $opt => $text ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $val, $opt, false ), esc_html( $text ) );
					}
					echo '</select>';
					break;
				case 'textarea':
					printf( '<textarea id="fwp-%1$s" name="%2$s" rows="5" class="large-text code">%3$s</textarea>', esc_attr( $key ), esc_attr( $name ), esc_textarea( (string) $val ) );
					break;
				default:
					printf( '<input type="text" class="regular-text" id="fwp-%1$s" name="%2$s" value="%3$s">', esc_attr( $key ), esc_attr( $name ), esc_attr( (string) $val ) );
			}
			if ( $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( 'Salvar e limpar o cache' );
		echo '</div></form>';

		if ( 'midia' === $tab ) {
			self::render_webp_box();
		} elseif ( 'banco' === $tab ) {
			self::render_db_box();
		} elseif ( 'avancado' === $tab ) {
			self::render_advanced_box();
		}
	}

	private static function render_webp_box() {
		echo '<div class="fwp-box fwp-btns"><h2>Converter imagens que já existem</h2>';
		if ( ! Webp::supported() ) {
			echo '<p class="fwp-bad">Este servidor não tem suporte a WebP no PHP (GD ou Imagick). Peça à hospedagem para ativar.</p></div>';
			return;
		}
		$state = get_option( Webp::BULK_OPTION );
		if ( $state ) {
			printf( '<p>%s · imagens verificadas: %d · versões WebP criadas: %d</p>', ! empty( $state['running'] ) ? '<span class="fwp-warn">Em andamento</span>' : '<span class="fwp-ok">Concluído</span>', (int) $state['offset'], (int) $state['converted'] );
		}
		printf( '<a class="button" href="%s">Converter todas as imagens da biblioteca</a>', esc_url( self::action_url( 'webp_bulk' ) ) );
		echo '<p class="description">Roda em segundo plano, em lotes. Os arquivos originais não são alterados.</p></div>';
	}

	private static function render_db_box() {
		echo '<div class="fwp-box fwp-btns"><h2>Limpar agora</h2><ul>';
		$labels = Database::labels();
		foreach ( Database::counts() as $k => $n ) {
			printf( '<li>%s: <strong>%d</strong></li>', esc_html( $labels[ $k ] ), (int) $n );
		}
		echo '</ul>';
		printf( '<a class="button button-primary" href="%s" onclick="return confirm(\'Os itens listados serão apagados de vez (não vão para a lixeira). Continuar?\')">Limpar o banco de dados</a>', esc_url( self::action_url( 'db_clean' ) ) );
		echo '<p class="description">Os itens apagados não podem ser recuperados. Faça um backup antes se tiver dúvida.</p></div>';
	}

	private static function render_advanced_box() {
		$rel = Updater::repo() ? Updater::latest() : null;
		echo '<div class="fwp-box fwp-btns"><h2>Atualizações</h2>';
		if ( ! Updater::repo() ) {
			echo '<p class="fwp-warn">Nenhum repositório do GitHub configurado. Preencha o campo acima para receber atualizações automáticas.</p>';
		} else {
			printf( '<p>Repositório: <code>%s</code> · versão instalada: <strong>%s</strong> · última no GitHub: <strong>%s</strong></p>', esc_html( Updater::repo() ), esc_html( FAST_WP_VERSION ), esc_html( $rel ? $rel['version'] : 'não consultada' ) );
		}
		printf( '<a class="button" href="%s">Verificar atualização agora</a>', esc_url( self::action_url( 'check_update' ) ) );
		echo '</div>';

		echo '<div class="fwp-box fwp-btns"><h2>Levar as configurações para outro site</h2>';
		printf( '<a class="button" href="%s">Baixar configurações (.json)</a>', esc_url( self::action_url( 'export' ) ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		wp_nonce_field( 'fast_wp_import' );
		echo '<input type="hidden" name="action" value="fast_wp"><input type="hidden" name="do" value="import">';
		echo '<textarea name="config" rows="5" class="large-text code" placeholder="Cole aqui o conteúdo do arquivo fast-wp-config.json"></textarea>';
		submit_button( 'Importar', 'secondary', 'submit', false );
		echo '<p class="description">Ao importar, o modo de teste é ligado no site de destino.</p></form></div>';

		echo '<div class="fwp-box fwp-btns"><h2>Apagar arquivos gerados</h2>';
		printf( '<a class="button" href="%s">Apagar cache e arquivos otimizados</a>', esc_url( self::action_url( 'purge_everything' ) ) );
		echo '<p class="description">Use se algo parecer desatualizado mesmo depois de limpar o cache. Tudo é gerado de novo nas próximas visitas.</p></div>';
	}
}
