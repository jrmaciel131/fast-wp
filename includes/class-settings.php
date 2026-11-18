<?php
/**
 * Configurações: campos, padrões, predefinições e o arquivo de configuração lido pelo advanced-cache.php.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'fast_wp_settings';

	/** @var array|null */
	private static $cache = null;

	/** Parâmetros de URL que não impedem o cache (tráfego pago e e-mail). */
	const IGNORE_PARAMS = "utm_*\ngclid\ngbraid\nwbraid\nfbclid\nmsclkid\nttclid\ntwclid\nli_fat_id\ndclid\n_ga\n_gl\nmc_cid\nmc_eid\nref\nsrsltid";

	/**
	 * Definição de todas as abas e campos. A tela de opções é montada a partir daqui.
	 */
	public static function fields() {
		return array(
			'cache'      => array(
				'title'  => 'Cache',
				'fields' => array(
					'cache_enabled'            => array( 'checkbox', 1, 'Ativar cache de página', 'Guarda o HTML pronto e entrega sem carregar o WordPress.' ),
					'cache_htaccess'           => array( 'checkbox', 1, 'Servir o cache direto pelo Apache (.htaccess)', 'Mais rápido. Se o servidor não for Apache/LiteSpeed, o cache é servido pelo PHP automaticamente.' ),
					'cache_lifespan'           => array( 'number', 10, 'Validade do cache (horas)', '0 = não expira. 10 horas é seguro para formulários do Elementor.' ),
					'cache_preload'            => array( 'checkbox', 1, 'Pré-carregar o cache de todo o site', 'Gera o cache das páginas e posts publicados em segundo plano.' ),
					'cache_purge_all'          => array( 'checkbox', 1, 'Limpar todo o cache quando qualquer página for atualizada', 'Recomendado para Elementor (cabeçalho e rodapé aparecem em todas as páginas).' ),
					'cache_mobile_separate'    => array( 'checkbox', 0, 'Cache separado para celular', 'Só é necessário se o tema mostrar HTML diferente no celular. Elementor não precisa.' ),
					'browser_cache'            => array( 'checkbox', 1, 'Cache do navegador', 'Imagens, CSS, JS e fontes ficam guardados no navegador do visitante por 1 ano.' ),
					'gzip'                     => array( 'checkbox', 1, 'Compressão GZIP', 'Reduz o tamanho dos arquivos enviados.' ),
					'cache_ignore_params'      => array( 'textarea', self::IGNORE_PARAMS, 'Parâmetros de URL ignorados', 'Um por linha. URLs com esses parâmetros (anúncios) continuam recebendo o cache. Use * no fim para prefixo.' ),
					'cache_exclude_urls'       => array( 'textarea', '', 'Nunca guardar em cache (URLs)', 'Um trecho de URL por linha, por exemplo /obrigado/' ),
					'cache_exclude_cookies'    => array( 'textarea', '', 'Nunca usar cache com estes cookies', 'Um prefixo de cookie por linha.' ),
				),
			),
			'arquivos'   => array(
				'title'  => 'CSS e HTML',
				'fields' => array(
					'minify_html'              => array( 'checkbox', 1, 'Minificar HTML', 'Remove comentários e espaços extras do HTML.' ),
					'minify_css'               => array( 'checkbox', 1, 'Minificar CSS', 'Gera cópias menores dos arquivos CSS do site.' ),
					'used_css'                 => array( 'checkbox', 1, 'Remover CSS não usado', 'Cada página recebe só o CSS que ela usa, direto no HTML. Resolve "Reduza o CSS não usado" e "Elimine recursos que bloqueiam a renderização".' ),
					'used_css_fallback'        => array( 'select', 'interaction', 'CSS completo depois', 'Quando carregar o CSS original, como garantia para menus, popups e efeitos.', array(
						'interaction' => 'Na primeira interação do visitante (recomendado)',
						'load'        => 'Logo depois que a página carregar',
						'none'        => 'Nunca (máximo, mais arriscado)',
					) ),
					'used_css_inline_styles'   => array( 'checkbox', 1, 'Também limpar blocos de CSS grandes dentro do HTML', 'Blocos <style> acima de 8 KB.' ),
					'used_css_safelist'        => array( 'textarea', '', 'Classes que nunca devem ser removidas', 'Uma por linha. Use * no fim para prefixo (ex.: meu-menu-*) ou /regex/.' ),
				),
			),
			'javascript' => array(
				'title'  => 'JavaScript',
				'fields' => array(
					'delay_js'                 => array( 'checkbox', 1, 'Atrasar JavaScript até a interação', 'Os scripts só carregam quando o visitante toca, rola ou move o mouse. É o que mais aumenta a nota no celular.' ),
					'delay_js_trackers'        => array( 'checkbox', 0, 'Atrasar também pixels e GTM (Meta, Google, TikTok, Clarity)', 'Aumenta a nota, mas o PageView só dispara depois da primeira interação. Deixe desligado se o rastreio de anúncios for prioridade.' ),
					'delay_js_timeout'         => array( 'number', 0, 'Carregar mesmo sem interação depois de (segundos)', '0 = só com interação.' ),
					'elementor_animations'     => array( 'checkbox', 1, 'Mostrar na hora os elementos com animação de entrada', 'Sem isso, elementos com animação do Elementor ficam invisíveis até o JS carregar.' ),
					'delay_exclude'            => array( 'textarea', '', 'Nunca atrasar (trechos do script)', 'Um por linha: parte da URL do arquivo ou do código do script.' ),
					'defer_js'                 => array( 'checkbox', 1, 'Adiar JavaScript (defer)', 'Os scripts que não forem atrasados deixam de bloquear a página.' ),
					'defer_jquery'             => array( 'checkbox', 1, 'Adiar também o jQuery', 'Scripts escritos direto na página que usam jQuery são adiados junto, na mesma ordem.' ),
					'defer_exclude'            => array( 'textarea', '', 'Nunca adiar (trechos do script)', 'Um por linha.' ),
					'minify_js'                => array( 'checkbox', 0, 'Minificar JS', 'Só arquivos que ainda não são .min.js. Ganho pequeno; deixe desligado se algo quebrar.' ),
				),
			),
			'midia'      => array(
				'title'  => 'Imagens e vídeos',
				'fields' => array(
					'lazy_images'              => array( 'checkbox', 1, 'Lazy load de imagens', 'Imagens fora da tela só carregam quando o visitante chega nelas.' ),
					'lazy_skip'                => array( 'number', 3, 'Não aplicar lazy load nas primeiras imagens', 'Quantidade de imagens do topo que carregam na hora (logo e banner).' ),
					'lazy_iframes'             => array( 'checkbox', 1, 'Lazy load de iframes (mapas, vídeos)', '' ),
					'youtube_facade'           => array( 'checkbox', 1, 'Trocar vídeos do YouTube por miniatura', 'O player só carrega no clique. Economiza cerca de 500 KB por vídeo.' ),
					'lazy_bg'                  => array( 'checkbox', 1, 'Lazy load de imagens de fundo escritas no HTML', 'Para fundos do Elementor, ative também "Lazy Load Background Images" em Elementor > Configurações > Recursos.' ),
					'lcp_priority'             => array( 'checkbox', 1, 'Priorizar a imagem principal (LCP)', 'A primeira imagem grande do conteúdo recebe prioridade alta e pré-carregamento.' ),
					'lcp_preload_urls'         => array( 'textarea', '', 'Pré-carregar estas imagens em todas as páginas', 'Uma URL por linha. Útil quando o banner é imagem de fundo.' ),
					'webp_convert'             => array( 'checkbox', 1, 'Criar versões WebP das imagens enviadas', 'Feito no próprio servidor, sem serviço externo.' ),
					'webp_serve'               => array( 'checkbox', 1, 'Usar as versões WebP no site', 'Troca JPG/PNG pela versão WebP quando ela existir e for menor.' ),
				),
			),
			'fontes'     => array(
				'title'  => 'Fontes',
				'fields' => array(
					'fonts_google_local'       => array( 'checkbox', 1, 'Hospedar Google Fonts no próprio site', 'Baixa as fontes uma vez e serve pelo seu domínio. Remove a conexão extra com o Google.' ),
					'fonts_swap'               => array( 'checkbox', 1, 'Texto visível enquanto a fonte carrega (font-display: swap)', 'Resolve "Garanta que o texto permaneça visível".' ),
					'fonts_preload'            => array( 'checkbox', 0, 'Pré-carregar a fonte principal', 'Pré-carrega o peso normal (400) das fontes do Google hospedadas localmente.' ),
				),
			),
			'limpeza'    => array(
				'title'  => 'Limpeza',
				'fields' => array(
					'cleanup_emojis'           => array( 'checkbox', 1, 'Desativar emojis do WordPress', 'Remove o script e o CSS de emojis (o navegador já mostra emojis).' ),
					'cleanup_embeds'           => array( 'checkbox', 1, 'Desativar embeds do WordPress', 'Remove o wp-embed.js.' ),
					'cleanup_head'             => array( 'checkbox', 1, 'Limpar o cabeçalho', 'Remove RSD, wlwmanifest, shortlink e a versão do WordPress.' ),
					'cleanup_xmlrpc'           => array( 'checkbox', 1, 'Desativar XML-RPC', 'Fecha uma porta usada em ataques. Desligue se usar o app do WordPress ou Jetpack.' ),
					'cleanup_dashicons'        => array( 'checkbox', 0, 'Remover Dashicons para visitantes', 'Ícones do painel que alguns temas carregam sem precisar.' ),
					'cleanup_block_css'        => array( 'checkbox', 0, 'Remover CSS do Gutenberg em páginas do Elementor', '' ),
					'cleanup_jquery_migrate'   => array( 'checkbox', 0, 'Remover jQuery Migrate', 'Pode quebrar plugins antigos.' ),
					'heartbeat'                => array( 'select', 'reduce', 'Heartbeat do WordPress', 'Requisições automáticas do painel.', array(
						'default' => 'Padrão do WordPress',
						'reduce'  => 'Reduzir para 60 segundos',
						'front'   => 'Desativar no site e reduzir no painel',
					) ),
				),
			),
			'cdn'        => array(
				'title'  => 'CDN',
				'fields' => array(
					'cdn_enabled'              => array( 'checkbox', 0, 'Usar CDN', 'Troca o endereço de imagens, CSS, JS e fontes pelo endereço da CDN.' ),
					'cdn_url'                  => array( 'text', '', 'Endereço da CDN', 'Ex.: https://cdn.seusite.com.br' ),
				),
			),
			'banco'      => array(
				'title'  => 'Banco de dados',
				'fields' => array(
					'db_weekly'                => array( 'checkbox', 0, 'Limpar o banco automaticamente toda semana', 'Revisões, rascunhos automáticos, lixeira, spam e transientes expirados.' ),
				),
			),
			'avancado'   => array(
				'title'  => 'Avançado',
				'fields' => array(
					'auto_update'              => array( 'checkbox', 1, 'Atualizar o plugin automaticamente pelo GitHub', '' ),
					'update_channel'           => array( 'select', 'stable', 'Canal de atualização', 'Use "Teste" em um site de teste para receber as versões antes dos outros.', array(
						'stable' => 'Estável',
						'beta'   => 'Teste (recebe pré-lançamentos)',
					) ),
					'github_repo'              => array( 'text', '', 'Repositório do GitHub', 'Formato usuario/repositorio. Vazio = usa o definido no código.' ),
				),
			),
		);
	}

	/** Valores internos que não aparecem como campo nas abas. */
	private static function internal_defaults() {
		return array(
			'test_mode' => 1,
			'safe_mode' => 0,
		);
	}

	/** Predefinições aplicadas pelos botões do painel. */
	public static function presets() {
		return array(
			'seguro'      => array(
				'label'  => 'Seguro',
				'values' => array(
					'delay_js'          => 0,
					'used_css'          => 0,
					'defer_jquery'      => 0,
					'minify_js'         => 0,
					'cleanup_dashicons' => 0,
					'cleanup_block_css' => 0,
				),
			),
			'recomendado' => array(
				'label'  => 'Recomendado',
				'values' => array(
					'delay_js'          => 1,
					'delay_js_trackers' => 0,
					'used_css'          => 1,
					'used_css_fallback' => 'interaction',
					'defer_js'          => 1,
					'defer_jquery'      => 1,
					'minify_js'         => 0,
					'cleanup_dashicons' => 0,
					'cleanup_block_css' => 0,
				),
			),
			'maximo'      => array(
				'label'  => 'Máximo',
				'values' => array(
					'delay_js'          => 1,
					'delay_js_trackers' => 1,
					'used_css'          => 1,
					'used_css_fallback' => 'interaction',
					'defer_js'          => 1,
					'defer_jquery'      => 1,
					'minify_js'         => 1,
					'cleanup_dashicons' => 1,
					'cleanup_block_css' => 1,
				),
			),
		);
	}

	public static function defaults() {
		$out = self::internal_defaults();
		foreach ( self::fields() as $tab ) {
			foreach ( $tab['fields'] as $key => $f ) {
				$out[ $key ] = $f[1];
			}
		}
		return $out;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Lê um campo de texto com uma regra por linha. */
	public static function lines( $key ) {
		$raw = (string) self::get( $key );
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Salva valores (mesclando com os atuais) e atualiza o arquivo usado pelo cache. */
	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, true );
		self::$cache = $all;
		self::write_config();
	}

	/** Limpa e valida os valores enviados por uma aba da tela de opções. */
	public static function sanitize_tab( $tab, array $input ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $tab ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $fields[ $tab ]['fields'] as $key => $f ) {
			$type = $f[0];
			$val  = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : null;
			switch ( $type ) {
				case 'checkbox':
					$out[ $key ] = empty( $val ) ? 0 : 1;
					break;
				case 'number':
					$out[ $key ] = max( 0, (int) $val );
					break;
				case 'select':
					$out[ $key ] = isset( $f[4][ $val ] ) ? $val : $f[1];
					break;
				case 'textarea':
					$out[ $key ] = sanitize_textarea_field( (string) $val );
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $val );
			}
		}
		if ( isset( $out['cdn_url'] ) ) {
			$out['cdn_url'] = untrailingslashit( esc_url_raw( $out['cdn_url'] ) );
		}
		if ( isset( $out['github_repo'] ) ) {
			$out['github_repo'] = preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $out['github_repo'] ) ? $out['github_repo'] : '';
		}
		return $out;
	}

	/** Hosts aceitos pelo cache (evita gerar cache para Host forjado). */
	public static function hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = wp_parse_url( $url, PHP_URL_HOST );
			if ( $h ) {
				$h       = strtolower( $h );
				$hosts[] = $h;
				$hosts[] = 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/** Configuração usada pelo advanced-cache.php (antes do WordPress carregar). */
	public static function cache_config() {
		$s = self::all();
		return array(
			'enabled'         => ! empty( $s['cache_enabled'] ),
			'dir'             => FAST_WP_CACHE_DIR,
			'request_class'   => FAST_WP_DIR . 'includes/class-request.php',
			'hosts'           => self::hosts(),
			'lifespan'        => (int) $s['cache_lifespan'] * HOUR_IN_SECONDS,
			'mobile_separate' => ! empty( $s['cache_mobile_separate'] ),
			'ignore_params'   => self::lines( 'cache_ignore_params' ),
			'exclude_urls'    => self::lines( 'cache_exclude_urls' ),
			'exclude_cookies' => self::lines( 'cache_exclude_cookies' ),
			'charset'         => get_option( 'blog_charset', 'UTF-8' ),
		);
	}

	public static function write_config() {
		if ( ! wp_mkdir_p( FAST_WP_CACHE_DIR ) ) {
			return false;
		}
		$code = "<?php\ndefined( 'ABSPATH' ) || exit;\n// Gerado pelo Fast WP. Não edite: é recriado ao salvar as opções.\nreturn " . var_export( self::cache_config(), true ) . ";\n"; // phpcs:ignore
		$file = FAST_WP_CACHE_DIR . '/config.php';
		$tmp  = $file . '.tmp';
		if ( false === file_put_contents( $tmp, $code ) ) { // phpcs:ignore
			return false;
		}
		return rename( $tmp, $file );
	}

	/** Assinatura das opções que mudam o resultado das otimizações (usada nos nomes dos arquivos gerados). */
	public static function signature() {
		$s    = self::all();
		$keys = array( 'minify_css', 'fonts_swap', 'webp_serve', 'cdn_enabled', 'cdn_url', 'minify_js' );
		$sig  = array();
		foreach ( $keys as $k ) {
			$sig[ $k ] = isset( $s[ $k ] ) ? $s[ $k ] : '';
		}
		return substr( md5( FAST_WP_VERSION . wp_json_encode( $sig ) ), 0, 8 );
	}

	/** Usado nos testes automatizados. */
	public static function reset_cache() {
		self::$cache = null;
	}
}
