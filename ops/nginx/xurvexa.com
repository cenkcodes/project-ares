server {
    listen 80;
    listen [::]:80;

    server_name xurvexa.com www.xurvexa.com;

    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;

    server_name xurvexa.com www.xurvexa.com;

    root /var/www/project-ares/backend/public;
    index index.php;

    charset utf-8;

    access_log /var/log/nginx/xurvexa_access.log;
    error_log /var/log/nginx/xurvexa_error.log;

    client_max_body_size 20M;

    ssl_certificate /etc/ssl/cloudflare/xurvexa-origin.pem;
    ssl_certificate_key /etc/ssl/cloudflare/xurvexa-origin.key;

    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    ssl_session_tickets off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }

    location ~ ^/index\.php(/|$) {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_hide_header X-Powered-By;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
