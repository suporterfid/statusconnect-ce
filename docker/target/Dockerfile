FROM node:22-bookworm-slim

WORKDIR /app

COPY docker/target/server.js docker/target/fake-asaas.js docker/target/fake-telegram.js docker/target/fake-probe.js docker/target/fake-zenvia.js docker/target/fake-mqtt.js docker/target/fake-mqtt-test.key docker/target/fake-mqtt-test.crt ./

EXPOSE 8080 1883 1884 8883

CMD ["node", "server.js"]
