FROM node:22-alpine AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.js tsconfig.json ./
RUN npm run build

FROM nginx:1.27-alpine
COPY public /var/www/html/public
COPY --from=frontend /app/public/build /var/www/html/public/build
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
