# PCIMS Deployment Guide

This guide provides instructions on how to deploy the Personal Collection Inventory Management System (PCIMS) to a cloud environment.

## 1. Prerequisites

Before you begin, you will need the following:

*   A cloud hosting provider (e.g., AWS, Google Cloud, Azure, DigitalOcean, Linode).
*   A domain name (optional, but recommended).
*   An SSH client (e.g., PuTTY, OpenSSH) to connect to your server.
*   A MySQL client (e.g., MySQL Workbench, DBeaver) to manage your database.

## 2. Server Setup

1.  **Create a new server instance:** Choose a server image with a LAMP (Linux, Apache, MySQL, PHP) or LEMP (Linux, Nginx, MySQL, PHP) stack pre-installed. A server with at least 1 GB of RAM is recommended.

2.  **Connect to your server:** Use your SSH client to connect to your server using the IP address and credentials provided by your hosting provider.

3.  **Update your server:** It's a good practice to update your server's packages to the latest version:

    ```bash
    sudo apt update
    sudo apt upgrade
    ```

4.  **Install Git:** You will need Git to clone the project repository.

    ```bash
    sudo apt install git
    ```

## 3. Database Configuration

1.  **Create a new MySQL database:**

    ```sql
    CREATE DATABASE pcims_db;
    ```

2.  **Create a new MySQL user:** Replace `your_password` with a strong password.

    ```sql
    CREATE USER 'pcims_user'@'localhost' IDENTIFIED BY 'your_password';
    ```

3.  **Grant privileges to the new user:**

    ```sql
    GRANT ALL PRIVILEGES ON pcims_db.* TO 'pcims_user'@'localhost';
    ```

4.  **Flush privileges:**

    ```sql
    FLUSH PRIVILEGES;
    ```

5.  **Import the database schema:** You can either use a MySQL client to import the `database.sql` file, or you can use the command line:

    ```bash
    mysql -u pcims_user -p pcims_db < database.sql
    ```

## 4. Code Deployment

1.  **Clone the project repository:** Clone the project to your server's web directory (e.g., `/var/www/html`).

    ```bash
    cd /var/www/html
    git clone https://github.com/your_username/pcims.git .
    ```

2.  **Configure the application:** Open the `config/config.php` file and update the database credentials:

    ```php
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'pcims_db');
    define('DB_USER', 'pcims_user');
    define('DB_PASS', 'your_password');
    ```

3.  **Set permissions:** Make sure the web server has write permissions to the `uploads` and `backups` directories:

    ```bash
    sudo chown -R www-data:www-data uploads
    sudo chown -R www-data:www-data backups
    sudo chmod -R 755 uploads
    sudo chmod -R 755 backups
    ```

## 5. Email Configuration

To enable email notifications, you need to configure the SMTP settings in `config/email_config.php`. It's recommended to use environment variables for sensitive information like passwords.

1.  **Set environment variables:** You can set environment variables in your server's configuration (e.g., in your Apache or Nginx configuration file, or in a `.env` file).

    ```
    PCIMS_SMTP_HOST="your_smtp_host"
    PCIMS_SMTP_PORT="your_smtp_port"
    PCIMS_SMTP_USER="your_smtp_username"
    PCIMS_SMTP_PASS="your_smtp_password"
    PCIMS_SMTP_FROM="your_from_email"
    PCIMS_SMTP_FROM_NAME="PCIMS System"
    ```

2.  **Enable email in the config:** In `config/config.php`, make sure `EMAIL_ENABLED` is set to `true`.

    ```php
    define('EMAIL_ENABLED', filter_var(getenv('PCIMS_EMAIL_ENABLED') ?: '1', FILTER_VALIDATE_BOOLEAN));
    ```

## 6. Final Steps

1.  **Configure your web server:** If you are using a domain name, configure your web server (Apache or Nginx) to point your domain to the project's directory.

2.  **Enable HTTPS:** It's highly recommended to enable HTTPS on your site to secure the communication between the client and the server. You can use Let's Encrypt to get a free SSL certificate.

3.  **Test your application:** Open your website in a browser and test all the features, including user registration, login, inventory management, and sales.

Congratulations! You have successfully deployed the PCIMS application to a cloud environment.
