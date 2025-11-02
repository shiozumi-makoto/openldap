#!/usr/bin/env python3
import struct
import sys

# --- MD4 implementation (pure Python) ---
# Reference: RFC 1320 / public domain adaptation

# Constants for MD4
def F(x, y, z): return ((x & y) | ((~x) & z))
def G(x, y, z): return ((x & y) | (x & z) | (y & z))
def H(x, y, z): return x ^ y ^ z

def rotl(x, n): return ((x << n) | (x >> (32 - n))) & 0xffffffff

def md4(message: bytes) -> bytes:
    message = bytearray(message)
    orig_len_in_bits = (8 * len(message)) & 0xffffffffffffffff
    message.append(0x80)
    while len(message) % 64 != 56:
        message.append(0)
    message += struct.pack("<Q", orig_len_in_bits)

    # Initial buffer values
    A, B, C, D = 0x67452301, 0xefcdab89, 0x98badcfe, 0x10325476

    for offset in range(0, len(message), 64):
        X = list(struct.unpack("<16I", message[offset:offset+64]))
        AA, BB, CC, DD = A, B, C, D

        # Round 1
        S = [3, 7, 11, 19]
        for i in range(16):
            k = i
            s = S[i % 4]
            A = rotl((A + F(B, C, D) + X[k]) & 0xffffffff, s)
            A, B, C, D = D, A, B, C

        # Round 2
        S = [3, 5, 9, 13]
        for i in range(16):
            k = (i % 4) * 4 + (i // 4)
            s = S[i % 4]
            A = rotl((A + G(B, C, D) + X[k] + 0x5a827999) & 0xffffffff, s)
            A, B, C, D = D, A, B, C

        # Round 3
        S = [3, 9, 11, 15]
        K = [0, 8, 4, 12, 2, 10, 6, 14, 1, 9, 5, 13, 3, 11, 7, 15]
        for i in range(16):
            k = K[i]
            s = S[i % 4]
            A = rotl((A + H(B, C, D) + X[k] + 0x6ed9eba1) & 0xffffffff, s)
            A, B, C, D = D, A, B, C

        A = (A + AA) & 0xffffffff
        B = (B + BB) & 0xffffffff
        C = (C + CC) & 0xffffffff
        D = (D + DD) & 0xffffffff

    return struct.pack("<4I", A, B, C, D)

# --- NTLM hash generator ---
def ntlm_hash(password: str) -> str:
    pw_bytes = password.encode('utf-16le')
    return md4(pw_bytes).hex().upper()

# --- Main Execution ---
if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python3 md4_nt_hash.py <password>")
        sys.exit(1)
    print(ntlm_hash(sys.argv[1]))




