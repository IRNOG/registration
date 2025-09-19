# IRNOG Registration Bot

## IPv6 only deployment on FreeBSD

###  Dependencies

```sh
pkg install -y php81 php81-curl php81-sqlite3
```

### Nginx

```sh
pkg install -y nginx php81-extensions
```

### Configuration

#### Nginx

under `/config/nginx.conf`:

```sh
cp /config/nginx.conf /usr/local/etc/nginx/
```

