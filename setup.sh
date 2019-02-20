#!/bin/bash

#Install Composer
php -r "readfile('https://getcomposer.org/installer');" > composer-setup.php
if [ -f composer-setup.php ]; then
    sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    php -r "unlink('composer-setup.php');"
fi

#Update APT
sudo apt-get update

#Install cURL
sudo apt-get install -y php7.2-curl

#Install ZIP
sudo apt-get install -y php7.2-zip

#Install mbstring
sudo apt-get install -y php7.2-mbstring

#Install DOM
sudo apt-get install -y php7.2-dom

#Install Required Packages
composer install --no-dev

#Change DocRoot
sed -i "s|Directory /var/www/html/|Directory /var/www/buyer/public/|" /etc/apache2/sites-enabled/000-default.conf
sed -i "s|DocumentRoot /var/www/html|DocumentRoot /var/www/buyer/public|" /etc/apache2/sites-enabled/000-default.conf

#Restart Mod2Rewrite
sudo a2enmod rewrite

#Restart Apache
systemctl restart apache2

#Create database
mysql -e "create database homestead;"
mysql -e "grant all privileges on homestead.* to 'homestead'@'localhost' identified by 'secret';"
mysql -e "flush privileges;"

#chmod folder
sudo chmod -R 777 /var/www/buyer/storage
sudo chmod 777 -R /var/www/buyer/bootstrap/cache

#make cookies folder
mkdir /var/www/buyer/storage/app/fut_cookies

#create env file
cp /var/www/buyer/.env.example /var/www/buyer/.env

#generate key
php artisan key:generate

#create database tables
php artisan migrate

#install default settings
php artisan db:seed

#create cron
line="* * * * * php /var/www/buyer/artisan schedule:run >> /dev/null 2>&1"
(crontab -u root -l; echo "$line" ) | crontab -u root -