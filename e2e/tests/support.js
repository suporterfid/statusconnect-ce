// Helpers for the browser tests (#173).
const path = require('path');
const { expect } = require('@playwright/test');

const i18nDir = path.resolve(__dirname, '../../frontend/src/i18n');
const catalogs = [require(path.join(i18nDir, 'en.json')), require(path.join(i18nDir, 'pt-BR.json'))];

const escapeRegExp = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/**
 * Regex for an operator app string in English or Portuguese, so the tests do
 * not depend on the user's locale. `params` fills {placeholders}.
 */
function text(key, params = {}) {
  const variants = catalogs.map((catalog) => {
    const value = key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), catalog);
    if (typeof value !== 'string') {
      throw new Error(`Missing i18n key: ${key}`);
    }
    return escapeRegExp(value.replace(/\{(\w+)\}/g, (_, name) => String(params[name] ?? `{${name}}`)));
  });
  return new RegExp(`^(${[...new Set(variants)].join('|')})$`);
}

const MAILPIT_URL = process.env.MAILPIT_URL || 'http://mailpit:8025';

const mailpit = {
  /** Waits for a message to `to` and returns the first URL matching `pattern` in it. */
  async findLink(to, pattern) {
    let found = null;
    await expect
      .poll(async () => {
        const search = await fetch(`${MAILPIT_URL}/api/v1/search?query=${encodeURIComponent(`to:"${to}"`)}`);
        const { messages = [] } = await search.json();
        for (const summary of messages) {
          const message = await (await fetch(`${MAILPIT_URL}/api/v1/message/${summary.ID}`)).json();
          const body = `${message.Text || ''}\n${(message.HTML || '').replace(/&amp;/g, '&')}`;
          const match = body.match(new RegExp(`https?://[^\\s"'<>]*${pattern.source.replace(/^\^/, '')}`));
          if (match) {
            found = match[0];
            return true;
          }
        }
        return false;
      }, { message: `email to ${to} with a link matching ${pattern}`, timeout: 30_000 })
      .toBe(true);
    return found;
  },
};

module.exports = { text, mailpit };
