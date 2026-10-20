/**
 * 新しいパスワードの長さ（06 §3.4・§9）。サーバーの App\Rules\PasswordRules と同じ値。
 * bcrypt は先頭 72 バイトしか使わないため、上限は UTF-8 のバイト数で見る（F-008）
 */
export const PASSWORD_MIN = 8
export const PASSWORD_MAX_BYTES = 72

/** 8 文字以上・72 バイト以内なら true */
export function isPasswordLengthValid(password: string): boolean {
  return [...password].length >= PASSWORD_MIN && new TextEncoder().encode(password).length <= PASSWORD_MAX_BYTES
}
