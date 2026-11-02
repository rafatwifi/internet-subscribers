FROM node:20-bookworm-slim

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --omit=dev

COPY index.js ./

ENV PORT=3001
ENV SEND_DELAY_MS=4000

EXPOSE 3001

CMD ["node", "index.js"]
