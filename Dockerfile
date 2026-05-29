FROM php:8.2-apache

# Ativa o módulo de reescrita do Apache (caso precise de rotas amigáveis)
RUN a2enmod rewrite

# Instala a extensão PDO MySQL para conectar com o banco de dados
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Copia os arquivos do seu projeto para dentro do servidor
COPY . /var/www/html/

# Expõe la porta padrão da web
EXPOSE 80S