FROM ubuntu:22.04 RUN apt-get update && apt-get install -y php && php -S localhost:8080
