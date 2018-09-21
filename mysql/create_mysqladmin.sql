/* create mysql webadmin */;
CREATE USER 'mysqladmin' IDENTIFIED WITH mysql_native_password BY 'mysqladmin';
GRANT ALL PRIVILEGES ON *.* TO 'mysqladmin' WITH GRANT OPTION;
FLUSH privileges;
