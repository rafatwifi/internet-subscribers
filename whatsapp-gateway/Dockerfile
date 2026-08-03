FROM node:20-bookworm-slim

WORKDIR /app

COPY package.docker.json package.json
RUN npm install --omit=dev

COPY index.docker.js index.js

ENV PORT=3001
ENV API_KEY=local-secret-change-me
ENV SEND_DELAY_MS=2500

EXPOSE 3001

CMD ["node", "index.js"]
