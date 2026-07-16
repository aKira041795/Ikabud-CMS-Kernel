FROM php:8.3-cli-alpine
RUN docker-php-ext-install pdo pdo_mysql
WORKDIR /workspace
COPY . .
ENTRYPOINT ["php", "scripts/workbench-ci.php"]
