FROM ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y \
    php-cli \
    php-mbstring \
    php-xml \
    php-curl \
    php-zip \
    python3 \
    python3-pip \
    python3-venv \
    git \
    curl \
    unzip \
    ca-certificates \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app

EXPOSE 8080

# Start PHP development server
CMD ["php", "-S", "0.0.0.0:8080"]
