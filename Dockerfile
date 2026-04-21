FROM dunglas/frankenphp

RUN install-php-extensions pdo_mysql mysqli

WORKDIR /app/public

COPY . /app/public