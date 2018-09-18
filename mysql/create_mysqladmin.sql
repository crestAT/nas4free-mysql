/* create mysql webadmin */;
CREATE USER 'mysqladmin'@'localhost' IDENTIFIED WITH mysql_native_password BY 'mysqladmin';
GRANT ALL PRIVILEGES ON *.* TO 'mysqladmin'@'localhost' WITH GRANT OPTION;
FLUSH privileges;
