# Fast WP

[English](README.md) · **Português**

Plugin de desempenho gratuito para WordPress, feito para sites com Elementor. Não tem versão paga nem serviço externo: tudo roda no seu próprio servidor, e cada recurso tem uma caixa para ligar ou desligar.

## Recursos

| Área | O que faz |
|---|---|
| Cache | Cache de página em disco (servido pelo Apache ou pelo PHP), GZIP, cache do navegador, pré-carregamento, limpeza automática ao editar no Elementor. URLs de anúncio (`utm_*`, `gclid`, `fbclid`, `gbraid`...) continuam usando o cache. |
| CSS | Minificação e **remoção do CSS não usado** por página, com lista segura para menus, popups e efeitos do Elementor. O CSS completo carrega depois da primeira interação, como garantia. |
| JavaScript | **Atraso até a interação**, defer (inclusive jQuery, mantendo a ordem dos scripts), minificação opcional. Pixels e GTM não são atrasados por padrão; o PixelYourSite é detectado e o jQuery é mantido junto. |
| Imagens | Lazy load, imagem principal (LCP) com prioridade e pré-carregamento, miniatura no lugar do player do YouTube, lazy load de fundos, WebP criado no servidor. |
| Fontes | Google Fonts servidas pelo próprio site, `font-display: swap`, pré-carregamento opcional da fonte principal. |
| Limpeza | Emojis, embeds, cabeçalho, XML-RPC, Dashicons, CSS do Gutenberg, jQuery Migrate, Heartbeat. |
| Outros | CDN, limpeza do banco, exportar e importar configurações entre sites, atualização automática pelo GitHub, painel em inglês e português. |

## Segurança

- **Modo de teste:** depois de instalado, os visitantes ainda não recebem as otimizações. Abra o site com `?fwp_test=1` para ver a versão otimizada (o botão do PageSpeed no painel já inclui isso). `?fwp_off=1` mostra a página sem nenhuma otimização.
- **Falha segura:** se uma otimização der erro, a página original é entregue e o erro aparece no painel.
- **Autoteste:** depois de cada atualização, o plugin abre a página inicial. Se encontrar erro, liga o modo de segurança sozinho e desliga as otimizações.
- **Emergência:** `define( 'FAST_WP_DISABLE', true );` no `wp-config.php` desliga tudo na hora.
- **Desativar desfaz tudo:** remove as regras do `.htaccess`, o `advanced-cache.php` e a linha `WP_CACHE` do `wp-config.php`.

## Instalação

1. Desative qualquer outro plugin de cache/otimização (WP Fastest Cache, WP Rocket...) e limpe o cache dele.
2. Baixe o `fast-wp.zip` da [última versão](../../releases/latest) e envie em Plugins > Adicionar novo > Enviar plugin.
3. No menu **Fast WP**, use **Testar no PageSpeed** e navegue pelo site com `?fwp_test=1`.
4. Se estiver tudo certo, clique em **Liberar otimizações para os visitantes**.

Recomendado no Elementor (Elementor > Configurações > Recursos): ativar "Lazy Load Background Images".

## Idioma

O plugin segue o idioma do WordPress por padrão. Para escolher outro, vá em **Fast WP > Avançado > Idioma do plugin**. As traduções ficam em `languages/{locale}.php`; para adicionar um idioma, copie o `languages/pt_BR.php`, traduza os valores e inclua o idioma em `I18n::LANGUAGES`.

## Atualizações pelo GitHub

Os sites só atualizam quando uma nova **versão** é publicada (tag `v*`). Um commit comum não atualiza nada.

1. Em **Fast WP > Avançado**, o campo do repositório precisa apontar para `usuario/repositorio` (ou defina `FAST_WP_GITHUB_REPO` no `fast-wp.php`).
2. Para lançar uma versão: mude a versão no cabeçalho e em `FAST_WP_VERSION` do `fast-wp.php`, faça o commit e envie uma tag:
   - `v0.2.0-beta.1` → pré-lançamento: só os sites no canal **Teste** recebem.
   - `v0.2.0` → versão estável: todos os sites recebem.
3. A GitHub Action confere a sintaxe (PHP 7.4 e 8.3), roda os testes, gera o `fast-wp.zip` e publica a versão.
4. Cada site consulta o GitHub a cada 6 horas e o WordPress instala sozinho.

Dica: deixe um site de menor risco no canal **Teste** e os outros no **Estável**.

## Desenvolvimento

```bash
php tests/run.php            # testes automáticos (não precisa de WordPress)
python bin/build-zip.py      # gera build/fast-wp.zip
```

Teste com uma página real (copie o HTML da página e os arquivos CSS para `tests/fixtures/`):

```bash
FWP_TEST_ROOT=tests/fixtures/site FWP_TEST_HOME=https://www.seusite.com.br php tests/real-site.php tests/fixtures/home.html
```

A estrutura dos arquivos está descrita no [README em inglês](README.md#structure). Os comentários do código são em inglês.

## Licença

[GPL-2.0-or-later](LICENSE), a mesma licença do WordPress.
