FROM node:20-alpine

WORKDIR /usr/src/app

COPY package*.json ./
RUN npm ci --only=production

COPY server.js ./

ENV PORT=4000
EXPOSE 4000

CMD ["node", "server.js"]
