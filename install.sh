#!/bin/bash

################################################################################
# Ikabud Kernel - Automated Installation Script
# Version: 1.0.0
# Description: Automated installer for Ikabud Kernel CMS Operating System
################################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuration
INSTALL_DIR="/var/www/html/ikabud-kernel"
DB_NAME="ikabud_kernel"
DB_USER="ikabud_user"
PHP_VERSION="8.1"
WEB_SERVER=""
SKIP_DEPS=false
SKIP_DB=false
SKIP_WEB=false

################################################################################
# Helper Functions
################################################################################

print_header() {
    echo -e "${CYAN}"
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║                                                                ║"
    echo "║              Ikabud Kernel Installation Script                ║"
    echo "║                        Version 1.0.0                           ║"
    echo "║                                                                ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

print_step() {
    echo -e "${CYAN}▶ $1${NC}"
}

check_root() {
    if [[ $EUID -ne 0 ]]; then
        print_error "This script must be run as root or with sudo"
        exit 1
    fi
}

check_os() {
    print_step "Checking operating system..."
    
    if [[ -f /etc/os-release ]]; then
        . /etc/os-release
        OS=$ID
        OS_VERSION=$VERSION_ID
        print_success "Detected: $PRETTY_NAME"
    else
        print_error "Cannot detect operating system"
        exit 1
    fi
    
    # Check if OS is supported
    case $OS in
        ubuntu|debian|centos|rhel|fedora)
            print_success "Operating system is supported"
            ;;
        *)
            print_warning "Operating system may not be fully supported"
            read -p "Continue anyway? (y/n) " -n 1 -r
            echo
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                exit 1
            fi
            ;;
    esac
}

check_requirements() {
    print_step "Checking system requirements..."
    
    local missing_deps=()
    
    # Check PHP
    if ! command -v php &> /dev/null; then
        missing_deps+=("php")
    else
        PHP_CURRENT_VERSION=$(php -r "echo PHP_VERSION;" | cut -d. -f1,2)
        if (( $(echo "$PHP_CURRENT_VERSION < $PHP_VERSION" | bc -l) )); then
            print_warning "PHP version $PHP_CURRENT_VERSION found, but $PHP_VERSION or higher is required"
            missing_deps+=("php$PHP_VERSION")
        else
            print_success "PHP $PHP_CURRENT_VERSION found"
        fi
    fi
    
    # Check MySQL/MariaDB
    if ! command -v mysql &> /dev/null; then
        missing_deps+=("mysql-client")
    else
        print_success "MySQL client found"
    fi
    
    # Check Composer
    if ! command -v composer &> /dev/null; then
        missing_deps+=("composer")
    else
        print_success "Composer found"
    fi
    
    # Check Git
    if ! command -v git &> /dev/null; then
        missing_deps+=("git")
    else
        print_success "Git found"
    fi
    
    # Check Curl
    if ! command -v curl &> /dev/null; then
        missing_deps+=("curl")
    else
        print_success "Curl found"
    fi
    
    if [ ${#missing_deps[@]} -gt 0 ]; then
        print_warning "Missing dependencies: ${missing_deps[*]}"
        return 1
    else
        print_success "All requirements met"
        return 0
    fi
}

install_dependencies() {
    print_step "Installing dependencies..."
    
    case $OS in
        ubuntu|debian)
            apt-get update
            apt-get install -y \
                php${PHP_VERSION} \
                php${PHP_VERSION}-cli \
                php${PHP_VERSION}-fpm \
                php${PHP_VERSION}-mysql \
                php${PHP_VERSION}-json \
                php${PHP_VERSION}-mbstring \
                php${PHP_VERSION}-xml \
                php${PHP_VERSION}-curl \
                php${PHP_VERSION}-zip \
                php${PHP_VERSION}-gd \
                mysql-client \
                curl \
                git \
                unzip
            
            # Install Composer
            if ! command -v composer &> /dev/null; then
                curl -sS https://getcomposer.org/installer | php
                mv composer.phar /usr/local/bin/composer
                chmod +x /usr/local/bin/composer
            fi
            ;;
            
        centos|rhel|fedora)
            yum install -y \
                php \
                php-cli \
                php-fpm \
                php-mysqlnd \
                php-json \
                php-mbstring \
                php-xml \
                php-curl \
                php-zip \
                php-gd \
                mysql \
                curl \
                git \
                unzip
            
            # Install Composer
            if ! command -v composer &> /dev/null; then
                curl -sS https://getcomposer.org/installer | php
                mv composer.phar /usr/local/bin/composer
                chmod +x /usr/local/bin/composer
            fi
            ;;
    esac
    
    print_success "Dependencies installed"
}

detect_web_server() {
    print_step "Detecting web server..."
    
    if systemctl is-active --quiet apache2 || systemctl is-active --quiet httpd; then
        WEB_SERVER="apache"
        print_success "Apache detected"
    elif systemctl is-active --quiet nginx; then
        WEB_SERVER="nginx"
        print_success "Nginx detected"
    else
        print_warning "No web server detected"
        echo "Which web server would you like to install?"
        echo "1) Apache"
        echo "2) Nginx"
        echo "3) Skip web server configuration"
        read -p "Enter choice [1-3]: " choice
        
        case $choice in
            1)
                WEB_SERVER="apache"
                install_apache
                ;;
            2)
                WEB_SERVER="nginx"
                install_nginx
                ;;
            3)
                WEB_SERVER="none"
                print_info "Skipping web server configuration"
                ;;
            *)
                print_error "Invalid choice"
                exit 1
                ;;
        esac
    fi
}

install_apache() {
    print_step "Installing Apache..."
    
    case $OS in
        ubuntu|debian)
            apt-get install -y apache2
            systemctl enable apache2
            systemctl start apache2
            a2enmod rewrite headers
            ;;
        centos|rhel|fedora)
            yum install -y httpd
            systemctl enable httpd
            systemctl start httpd
            ;;
    esac
    
    print_success "Apache installed"
}

install_nginx() {
    print_step "Installing Nginx..."
    
    case $OS in
        ubuntu|debian)
            apt-get install -y nginx
            systemctl enable nginx
            systemctl start nginx
            ;;
        centos|rhel|fedora)
            yum install -y nginx
            systemctl enable nginx
            systemctl start nginx
            ;;
    esac
    
    print_success "Nginx installed"
}

setup_database() {
    print_step "Setting up database..."
    
    # Generate random password
    DB_PASSWORD=$(openssl rand -base64 16)
    
    # Prompt for MySQL root password
    read -sp "Enter MySQL root password: " MYSQL_ROOT_PASSWORD
    echo
    
    # Create database and user
    mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<EOF
CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF
    
    if [ $? -eq 0 ]; then
        print_success "Database created: $DB_NAME"
        print_success "Database user created: $DB_USER"
        
        # Import schema
        if [ -f "${INSTALL_DIR}/database/schema.sql" ]; then
            mysql -u ${DB_USER} -p"${DB_PASSWORD}" ${DB_NAME} < "${INSTALL_DIR}/database/schema.sql"
            print_success "Database schema imported"
        fi
    else
        print_error "Failed to create database"
        exit 1
    fi
}

configure_environment() {
    print_step "Configuring environment..."
    
    # Copy .env.example to .env
    cp "${INSTALL_DIR}/.env.example" "${INSTALL_DIR}/.env"
    
    # Generate JWT secret
    JWT_SECRET=$(openssl rand -base64 32)
    
    # Update .env file
    sed -i "s|JWT_SECRET=.*|JWT_SECRET=${JWT_SECRET}|g" "${INSTALL_DIR}/.env"
    sed -i "s|DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|g" "${INSTALL_DIR}/.env"
    sed -i "s|DB_USERNAME=.*|DB_USERNAME=${DB_USER}|g" "${INSTALL_DIR}/.env"
    sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=${DB_PASSWORD}|g" "${INSTALL_DIR}/.env"
    
    # Prompt for admin credentials
    echo
    print_info "Set up admin credentials:"
    read -p "Admin username [admin]: " ADMIN_USER
    ADMIN_USER=${ADMIN_USER:-admin}
    
    read -sp "Admin password: " ADMIN_PASSWORD
    echo
    
    read -p "Admin email: " ADMIN_EMAIL
    
    sed -i "s|ADMIN_USERNAME=.*|ADMIN_USERNAME=${ADMIN_USER}|g" "${INSTALL_DIR}/.env"
    sed -i "s|ADMIN_PASSWORD=.*|ADMIN_PASSWORD=${ADMIN_PASSWORD}|g" "${INSTALL_DIR}/.env"
    sed -i "s|ADMIN_EMAIL=.*|ADMIN_EMAIL=${ADMIN_EMAIL}|g" "${INSTALL_DIR}/.env"
    
    print_success "Environment configured"
}

install_composer_dependencies() {
    print_step "Installing Composer dependencies..."
    
    cd "${INSTALL_DIR}"
    composer install --no-dev --optimize-autoloader --no-interaction
    
    print_success "Composer dependencies installed"
}

set_permissions() {
    print_step "Setting file permissions..."
    
    # Detect web server user
    if [ "$WEB_SERVER" = "apache" ]; then
        case $OS in
            ubuntu|debian)
                WEB_USER="www-data"
                ;;
            centos|rhel|fedora)
                WEB_USER="apache"
                ;;
        esac
    elif [ "$WEB_SERVER" = "nginx" ]; then
        WEB_USER="www-data"
    else
        WEB_USER="www-data"
    fi
    
    # Set ownership
    chown -R ${WEB_USER}:${WEB_USER} "${INSTALL_DIR}"
    
    # Set directory permissions
    find "${INSTALL_DIR}" -type d -exec chmod 755 {} \;
    
    # Set file permissions
    find "${INSTALL_DIR}" -type f -exec chmod 644 {} \;
    
    # Make CLI tools executable
    chmod +x "${INSTALL_DIR}/ikabud"
    chmod +x "${INSTALL_DIR}"/bin/*
    
    # Set writable directories
    chmod -R 775 "${INSTALL_DIR}/storage"
    chmod -R 775 "${INSTALL_DIR}/instances"
    chmod -R 775 "${INSTALL_DIR}/themes"
    chmod -R 775 "${INSTALL_DIR}/logs"
    
    # Create required directories
    mkdir -p "${INSTALL_DIR}/storage/cache"
    mkdir -p "${INSTALL_DIR}/storage/logs"
    mkdir -p "${INSTALL_DIR}/logs"
    
    print_success "File permissions set"
}

configure_web_server() {
    if [ "$WEB_SERVER" = "none" ]; then
        return
    fi
    
    print_step "Configuring web server..."
    
    # Prompt for domain
    read -p "Enter domain name (e.g., ikabud.local): " DOMAIN
    DOMAIN=${DOMAIN:-ikabud.local}
    
    if [ "$WEB_SERVER" = "apache" ]; then
        configure_apache "$DOMAIN"
    elif [ "$WEB_SERVER" = "nginx" ]; then
        configure_nginx "$DOMAIN"
    fi
}

configure_apache() {
    local domain=$1
    
    cat > /etc/apache2/sites-available/ikabud-kernel.conf <<EOF
<VirtualHost *:80>
    ServerName ${domain}
    ServerAlias www.${domain}
    DocumentRoot ${INSTALL_DIR}/public

    <Directory ${INSTALL_DIR}/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/ikabud-kernel-error.log
    CustomLog \${APACHE_LOG_DIR}/ikabud-kernel-access.log combined

    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
</VirtualHost>
EOF
    
    a2ensite ikabud-kernel.conf
    systemctl reload apache2
    
    print_success "Apache configured for $domain"
}

configure_nginx() {
    local domain=$1
    
    cat > /etc/nginx/sites-available/ikabud-kernel <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${domain} www.${domain};
    root ${INSTALL_DIR}/public;

    index index.php index.html;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/ikabud-kernel-access.log;
    error_log /var/log/nginx/ikabud-kernel-error.log;
}
EOF
    
    ln -sf /etc/nginx/sites-available/ikabud-kernel /etc/nginx/sites-enabled/
    nginx -t && systemctl reload nginx
    
    print_success "Nginx configured for $domain"
}

install_cli_tool() {
    print_step "Installing CLI tool..."
    
    ln -sf "${INSTALL_DIR}/ikabud" /usr/local/bin/ikabud
    
    print_success "CLI tool installed (ikabud command available globally)"
}

print_summary() {
    echo
    echo -e "${GREEN}"
    echo "╔════════════════════════════════════════════════════════════════╗"
    echo "║                                                                ║"
    echo "║           Ikabud Kernel Installation Complete! 🚀             ║"
    echo "║                                                                ║"
    echo "╚════════════════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    echo
    print_info "Installation Summary:"
    echo "  Install Directory: ${INSTALL_DIR}"
    echo "  Database: ${DB_NAME}"
    echo "  Database User: ${DB_USER}"
    echo "  Database Password: ${DB_PASSWORD}"
    echo "  Admin Username: ${ADMIN_USER}"
    echo "  Domain: ${DOMAIN:-localhost}"
    echo
    print_warning "IMPORTANT: Save these credentials securely!"
    echo
    print_info "Next Steps:"
    echo "  1. Access the admin panel: http://${DOMAIN:-localhost}/admin"
    echo "  2. Login with your admin credentials"
    echo "  3. Change the default admin password"
    echo "  4. Create your first CMS instance: ikabud create <instance-id>"
    echo "  5. Read the documentation: ${INSTALL_DIR}/docs/"
    echo
    print_info "Useful Commands:"
    echo "  ikabud help          - Show all available commands"
    echo "  ikabud list          - List all instances"
    echo "  ikabud status        - Check kernel status"
    echo
    print_success "Installation completed successfully!"
    echo
}

################################################################################
# Main Installation Flow
################################################################################

main() {
    print_header
    
    # Check if running as root
    check_root
    
    # Check operating system
    check_os
    
    # Check if already installed
    if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/.env" ]; then
        print_warning "Ikabud Kernel appears to be already installed at $INSTALL_DIR"
        read -p "Do you want to reinstall? This will overwrite existing files. (y/n) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_info "Installation cancelled"
            exit 0
        fi
    fi
    
    # Check requirements
    if ! check_requirements; then
        read -p "Install missing dependencies? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            install_dependencies
        else
            print_error "Cannot proceed without required dependencies"
            exit 1
        fi
    fi
    
    # Detect web server
    detect_web_server
    
    # Install Composer dependencies
    if [ -d "$INSTALL_DIR" ]; then
        install_composer_dependencies
    else
        print_error "Installation directory not found: $INSTALL_DIR"
        print_info "Please clone the repository first or extract the release package"
        exit 1
    fi
    
    # Setup database
    read -p "Configure database? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        setup_database
    fi
    
    # Configure environment
    configure_environment
    
    # Set permissions
    set_permissions
    
    # Configure web server
    if [ "$WEB_SERVER" != "none" ]; then
        read -p "Configure web server? (y/n) " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            configure_web_server
        fi
    fi
    
    # Install CLI tool
    install_cli_tool
    
    # Print summary
    print_summary
}

# Run main installation
main "$@"
