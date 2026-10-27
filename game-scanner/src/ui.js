import readline from 'node:readline';

// The console the program talks to the player through: plain lines, questions, yes / no, "press any key", and
// the commands typed while it waits for the hotkey. Tests give the session one of their own with the same
// methods.
export function createConsoleUi({ input = process.stdin, output = process.stdout } = {}) {
  const tty = Boolean(output.isTTY);
  const paint = (code) => (text) => (tty ? `\x1b[${code}m${text}\x1b[0m` : text);
  const write = (text) => output.write(`${text}\n`);
  let rl = null;
  let onLine = null;

  // One line interface for the program's life (several on one input lose keys): answers go to whoever asks.
  // When the input ends (a closed window, a pipe at its end), every question gets an empty answer.
  let ended = false;
  const lines = () => {
    if (!rl) {
      rl = readline.createInterface({ input, output, terminal: tty });
      rl.on('line', (line) => onLine?.(line));
      rl.on('SIGINT', () => process.emit('SIGINT')); // Ctrl+C while a question waits: the program's own quit
      rl.on('close', () => {
        rl = null;
        if (input.readableEnded || input.destroyed) {
          ended = true;
          onLine?.('');
        }
      });
    }
    return rl;
  };
  const nextLine = (prompt) =>
    new Promise((resolve) => {
      if (prompt) output.write(prompt);
      if (ended) return resolve('');
      onLine = (line) => {
        onLine = null;
        resolve(line.trim());
      };
      lines();
    });

  return {
    print: (text = '') => write(text),
    heading: (text) => write(paint('1')(text)),
    ok: (text) => write(paint('32')(text)),
    warn: (text) => write(paint('33')(text)),
    error: (text) => write(paint('31')(text)),
    // A sound, for the player who is in the game while the console says something.
    beep: () => output.write('\x07'),
    ask: (question) => nextLine(question),
    async confirm(question, yes = true) {
      const answer = (await nextLine(`${question} ${yes ? '[Y/n]' : '[y/N]'} `)).toLowerCase();
      return answer ? answer.startsWith('y') : yes;
    },
    // Calls fn(line) for each line typed until the returned function is called.
    commands(fn) {
      lines();
      onLine = fn;
      return () => {
        if (onLine === fn) onLine = null;
      };
    },
    // Waits for any key (Enter where single keys can't be read).
    async waitKey(prompt) {
      if (!input.isTTY || !input.setRawMode || ended) return void (await nextLine(prompt));
      rl?.close();
      rl = null;
      output.write(prompt);
      await new Promise((resolve) => {
        input.setRawMode(true);
        input.resume();
        input.once('data', () => {
          input.setRawMode(false);
          input.pause();
          resolve();
        });
      });
      output.write('\n');
    },
    close() {
      rl?.close();
      rl = null;
    },
  };
}
