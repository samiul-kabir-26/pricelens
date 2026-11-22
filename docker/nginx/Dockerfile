# nginx with the API's public/ assets baked in (Horizon dashboard assets, robots.txt, favicon).
FROM nginx:1.28-alpine
COPY api/public /var/www/api/public
ARG NGINX_CONF=default.conf
COPY docker/nginx/${NGINX_CONF} /etc/nginx/conf.d/default.conf
