FROM php:8.3-cli-bookworm

WORKDIR /app

# Install required dependencies for code judging (Python, Node.js, G++) and libzip for Excel processing
RUN apt-get update && apt-get install -y --no-install-recommends \
    libzip-dev \
    unzip \
    zip \
    python3 \
    nodejs \
    g++ \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

COPY . .

RUN mkdir -p data/results

EXPOSE 5000

CMD ["php", "-S", "0.0.0.0:5000", "index.php"]
