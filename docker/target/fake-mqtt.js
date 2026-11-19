'use strict';

/*
 * Fake MQTT broker for the tests of MQTT monitors (#200).
 *
 * It only answers CONNECT with a CONNACK and closes on DISCONNECT. That is
 * all an MQTT monitor does (app/Infrastructure/Mqtt/MqttConnectProbe.php).
 *
 *   1883  plain MQTT
 *   8883  MQTT over TLS, self-signed test certificate for "target"
 *         (fake-mqtt-test.crt/.key, valid for tests only)
 *   1884  accepts TCP and never answers, like a proxy whose broker is gone
 *
 * CONNACK return code by user name:
 *   monitor  + password "secret-pass" -> 0 (accepted)
 *   monitor  + any other password     -> 4 (bad user name or password)
 *   down                              -> 3 (server unavailable)
 *   (none)                            -> 5 (not authorized)
 */

const fs = require('fs');
const net = require('net');
const path = require('path');
const tls = require('tls');

function readString(buf, offset) {
  const len = buf.readUInt16BE(offset);
  return [buf.slice(offset + 2, offset + 2 + len).toString('utf8'), offset + 2 + len];
}

/** Parses a CONNECT packet; returns { username, password } or null. */
function parseConnect(buf) {
  if (buf.length < 2 || buf[0] !== 0x10) return null;
  let multiplier = 1;
  let remaining = 0;
  let i = 1;
  let byte;
  do {
    byte = buf[i++];
    remaining += (byte & 0x7f) * multiplier;
    multiplier *= 128;
  } while ((byte & 0x80) !== 0 && i < 5);
  if (buf.length < i + remaining) return null;

  let offset = i;
  let protocol;
  [protocol, offset] = readString(buf, offset);
  if (protocol !== 'MQTT') return { bad: true };
  const flags = buf[offset + 1];
  offset += 4; // level, flags, keep alive
  let clientId;
  [clientId, offset] = readString(buf, offset);
  let username = null;
  let password = null;
  if (flags & 0x80) [username, offset] = readString(buf, offset);
  if (flags & 0x40) [password, offset] = readString(buf, offset);
  return { clientId, username, password };
}

function handle(socket) {
  let data = Buffer.alloc(0);
  socket.on('error', () => {});
  socket.on('data', (chunk) => {
    data = Buffer.concat([data, chunk]);
    if (data[0] === 0xe0) {
      socket.end();
      return;
    }
    const connect = parseConnect(data);
    if (connect === null) return;
    data = Buffer.alloc(0);
    let code = 5;
    if (connect.bad) code = 1;
    else if (connect.username === 'down') code = 3;
    else if (connect.username === 'monitor') code = connect.password === 'secret-pass' ? 0 : 4;
    socket.write(Buffer.from([0x20, 0x02, 0x00, code]));
  });
}

function startFakeMqtt() {
  net.createServer(handle).listen(1883, '0.0.0.0');
  tls.createServer({
    key: fs.readFileSync(path.join(__dirname, 'fake-mqtt-test.key')),
    cert: fs.readFileSync(path.join(__dirname, 'fake-mqtt-test.crt')),
  }, handle).listen(8883, '0.0.0.0');
  // Accepts and stays silent, like an nginx stream proxy with no upstream.
  net.createServer((socket) => socket.on('error', () => {})).listen(1884, '0.0.0.0');
}

module.exports = { startFakeMqtt };
