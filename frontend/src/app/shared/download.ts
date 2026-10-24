/** The file name the server gave (Content-Disposition), else the fallback. */
export function downloadName(disposition: string | null, fallback: string): string {
  return disposition?.match(/filename="([^"]+)"/)?.[1] ?? fallback;
}

/** Hands a file to the browser to save. */
export function saveFile(blob: Blob, name: string): void {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = name;
  link.style.display = 'none';
  document.body.appendChild(link);
  link.click();
  link.remove();
  // Some browsers read the address only after the click has returned; a while later nothing needs it.
  setTimeout(() => URL.revokeObjectURL(url), 40_000);
}
