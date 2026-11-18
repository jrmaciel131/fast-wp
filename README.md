# Fast WP

Plugin de desempenho para WordPress + Elementor, sem versão paga e sem serviço externo.
Tudo roda no próprio servidor e cada recurso tem uma caixa para ligar ou desligar.

## O que ele faz

| Área | Recursos |
|---|---|
| Cache | Cache de página em disco (servido pelo Apache ou pelo PHP), GZIP, cache do navegador, pré-carregamento, limpeza automática ao editar no Elementor. URLs de anúncio (`utm_*`, `gclid`, `fbclid`, `gbraid`...) continuam usando o cache. |
| CSS | Minificação, **remoção do CSS não usado** por página (com lista segura para menus, popups e efeitos do Elementor), CSS completo carregado depois da primeira interação como garantia. |
| JavaScript | **Atraso até a interação**, defer (inclusive jQuery, mantendo a ordem dos scripts), minificação opcional. Pixels e GTM não são atrasados por padrão (PixelYourSite é detectado e o jQuery é mantido junto). |
| Imagens | Lazy load, imagem principal (LCP) com prioridade e preload, miniatura no lugar do YouTube, lazy load de fundos, WebP criado no servidor. |
| Fontes | Google Fonts servidas pelo próprio site, `font-display: swap`, preload da fonte principal. |
| Limpeza | Emojis, embeds, cabeçalho, XML-RPC, Dashicons, CSS do Gutenberg, jQuery Migrate, Heartbeat. |
| Outros | CDN, limpeza do banco, exportar e importar configurações entre sites, atualização automática pelo GitHub. |

## Segurança

- **Modo de teste:** depois de instalado, os visitantes ainda não recebem as otimizações. Para ver a versão otimizada, abra o site com `?fwp_test=1` (o link do PageSpeed no painel já inclui isso). `?fwp_off=1` mostra a página sem nenhuma otimização.
- **Falha segura:** se uma otimização der erro, a página original é entregue e o erro aparece no painel.
- **Autoteste:** depois de cada atualização, o plugin abre a página inicial. Se houver erro, ele liga o modo de segurança sozinho e desliga as otimizações.
- **Emergência:** `define( 'FAST_WP_DISABLE', true );` no `wp-config.php` desliga tudo na hora.
- **Desativar desfaz tudo:** remove as regras do `.htaccess`, o `advanced-cache.php` e a linha `WP_CACHE` do `wp-config.php`.

## Instalação

1. Desative o WP Fastest Cache (ou outro plugin de cache/otimização) e limpe o cache dele.
2. Envie o `fast-wp.zip` em Plugins > Adicionar novo > Enviar plugin e ative.
3. No menu **Fast WP**, use **Testar no PageSpeed** e navegue pelo site com `?fwp_test=1`.
4. Se estiver tudo certo, clique em **Liberar otimizações para os visitantes**.

Recomendado no Elementor (Elementor > Configurações > Recursos): ativar "Lazy Load Background Images".

## Atualizações pelo GitHub

1. Crie o repositório no GitHub e envie este código.
2. Em **Fast WP > Avançado**, preencha `usuario/repositorio` (ou defina `FAST_WP_GITHUB_REPO` no `fast-wp.php` antes de gerar o zip).
3. Para lançar uma versão: mude a versão no cabeçalho e em `FAST_WP_VERSION` do `fast-wp.php`, faça o commit e crie a tag:
   - `v0.2.0-beta.1` → pré-lançamento: só os sites no canal **Teste** recebem.
   - `v0.2.0` → versão estável: todos os sites recebem.
4. A GitHub Action confere a sintaxe (PHP 7.4 e 8.3), roda os testes, gera o `fast-wp.zip` e publica a release.

Sugestão: deixe um site de menor risco no canal **Teste** e os outros no **Estável**.

## Testes

```bash
php tests/run.php
```

Teste com uma página real (copie o HTML e os CSS do site para `tests/fixtures/`):

```bash
FWP_TEST_ROOT=tests/fixtures/site FWP_TEST_HOME=https://www.seusite.com.br php tests/real-site.php tests/fixtures/home.html
```

## Estrutura

```
fast-wp.php                     cabeçalho do plugin e carregamento
includes/class-plugin.php       ganchos, ativação, desativação, agendamentos
includes/class-settings.php     campos das abas, padrões, predefinições
includes/class-request.php      regras do cache (também usada pelo advanced-cache.php)
includes/class-cache.php        captura, gravação, limpeza e pré-carregamento do cache
includes/class-htaccess.php     regras do Apache
includes/class-optimizer.php    junta as otimizações (com falha segura)
includes/class-css.php          minificação e CSS não usado
includes/class-used-css.php     decide quais regras de CSS a página usa
includes/class-css-parser.php   leitor de CSS
includes/class-js.php           atraso, defer e o carregador do navegador
includes/class-media.php        lazy load, LCP, YouTube, fundos, WebP
includes/class-fonts.php        Google Fonts local e font-display
includes/class-webp.php         conversão para WebP no servidor
includes/class-cleanup.php      limpeza do WordPress
includes/class-database.php     limpeza do banco
includes/class-self-test.php    autoteste
includes/class-updater.php      atualizações pelo GitHub
includes/class-admin.php        tela de opções, avisos e barra de administração
```

Licença: GPL-2.0-or-later.
