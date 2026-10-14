FROM node:20-slim

# Crear usuario estándar para cumplir con las directrices de seguridad de Hugging Face Spaces (UID 1000)
RUN useradd -m -u 1000 user

WORKDIR /home/user/app

COPY --chown=user:user package*.json ./

USER user

ENV NODE_ENV=production \
    PORT=7860 \
    HOST=0.0.0.0 \
    HOME=/home/user

RUN npm install --omit=dev

COPY --chown=user:user . .

EXPOSE 7860

CMD ["node", "server.js"]

