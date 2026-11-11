#!/usr/bin/env bash
# Instala o servidor do app Comercial + Sidnet num Debian 11/12/13.
#   sudo DOMINIO=app.sidnet.com.br bash install.sh
# Não apaga nada do sistema antigo: pare e remova o antigo você mesmo, depois do backup.
set -euo pipefail

DOMINIO="${DOMINIO:?Informe o domínio: sudo DOMINIO=app.sidnet.com.br bash install.sh}"
REPO="${REPO:-https://github.com/sidnettelecom/comercial-sidnet-app.git}"
DEST=/opt/comercial-sidnet
DADOS=/var/lib/comercial-sidnet
CONF=/etc/comercial-sidnet

[ "$(id -u)" = 0 ] || { echo "Rode com sudo."; exit 1; }

echo "== Pacotes"
apt-get update -q
apt-get install -y -q nginx php-fpm php-cli php-sqlite3 php-curl php-xml php-mbstring git certbot python3-certbot-nginx

echo "== Código em $DEST"
if [ -d "$DEST/.git" ]; then git -C "$DEST" pull --ff-only; else git clone "$REPO" "$DEST"; fi

echo "== Dados e configuração"
install -d -o www-data -g www-data -m 750 "$DADOS"
install -d -m 750 -g www-data "$CONF"
if [ ! -f "$CONF/config.php" ]; then
  install -m 640 -g www-data "$DEST/server/config.exemplo.php" "$CONF/config.php"
  echo ">> Edite $CONF/config.php (usuário do CRM e senha do painel) antes de usar."
fi

echo "== nginx"
SOCK=$(ls /run/php/php*-fpm.sock | head -1)
sed -e "s#DOMINIO#$DOMINIO#" -e "s#PHPSOCK#$SOCK#" "$DEST/server/deploy/nginx.conf" > /etc/nginx/sites-available/comercial-sidnet
ln -sf /etc/nginx/sites-available/comercial-sidnet /etc/nginx/sites-enabled/comercial-sidnet
nginx -t && systemctl reload nginx

echo "== Repasse ao CRM a cada minuto"
cat > /etc/cron.d/comercial-sidnet <<CRON
* * * * * www-data COMERCIAL_CONFIG=$CONF/config.php php $DEST/server/bin/repassar.php >> $DADOS/repasse.log 2>&1
CRON

echo "== HTTPS"
certbot --nginx -d "$DOMINIO" --non-interactive --agree-tos --register-unsafely-without-email --redirect || \
  echo ">> O certificado falhou. Confira se $DOMINIO aponta para este servidor e rode: certbot --nginx -d $DOMINIO"

echo
echo "Pronto. Painel: https://$DOMINIO/painel/"
echo "Próximos passos:"
echo "  1. nano $CONF/config.php   (crm_usuario, crm_senha, painel_senha_hash)"
echo "  2. Abra o painel, carregue os vendedores do CRM e crie usuário e senha de cada vendedor."
