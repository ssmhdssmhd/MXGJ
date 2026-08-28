FROM php:8.2-fpm-alpine

MAINTAINER MXGJ Team <https://github.com/ssmhdssmhd/MXGJ>

# 必需 PHP 扩展
RUN apk add --no-cache \
    curl \
    libzip-dev \
    libpng-dev \
    && docker-php-ext-install curl mbstring json zip \
    && apk del libzip-dev libpng-dev

# 系统工具
RUN apk add --no-cache curl wget

# 设置时区（默认 Asia/Shanghai，可在 compose 覆盖）
ENV TZ=Asia/Shanghai
RUN apk add --no-cache tzdata && \
    cp /usr/share/zoneinfo/$TZ /etc/localtime && \
    echo $TZ > /etc/timezone && \
    apk del tzdata

# 复制项目
WORKDIR /var/www/html
COPY . /var/www/html/

# 运行时目录权限
RUN mkdir -p data/cache data/cookies data/logs data/cron && \
    chmod -R 777 data config && \
    chown -R www-data:www-data /var/www/html

EXPOSE 9000

# 健康检查
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s \
  CMD curl -sf http://localhost:9000/index.php -o /dev/null || exit 1
