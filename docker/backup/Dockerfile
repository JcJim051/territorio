FROM alpine:3.21
RUN apk add --no-cache bash curl openssl postgresql16-client rclone tzdata
COPY docker/backup/backup.sh /usr/local/bin/backup
RUN chmod +x /usr/local/bin/backup
CMD ["sh", "-c", "while true; do /usr/local/bin/backup; sleep 14400; done"]
