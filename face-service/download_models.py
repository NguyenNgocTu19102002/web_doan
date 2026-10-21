"""Download pinned OpenCV Zoo models, verify hashes, preserve model licenses."""
import hashlib
import os
from pathlib import Path
import urllib.request

COMMIT = '48b10fa505aeb51fbd127fc60a5c660040e823b7'
MODELS = [
    ('face_detection_yunet', 'face_detection_yunet_2023mar.onnx', '8f2383e4dd3cfbb4553ea8718107fc0423210dc964f9f4280604804ed2552fa4'),
    ('face_recognition_sface', 'face_recognition_sface_2021dec.onnx', '0ba9fbfa01b5270c96627c4ef784da859931e02f04419c829e83484087c34e79'),
]


def main():
    root = Path(os.environ.get('FACE_MODEL_DIR', 'models'))
    root.mkdir(parents=True, exist_ok=True)
    for folder, filename, expected in MODELS:
        target = root / filename
        if not target.exists() or hashlib.sha256(target.read_bytes()).hexdigest() != expected:
            url = f'https://media.githubusercontent.com/media/opencv/opencv_zoo/{COMMIT}/models/{folder}/{filename}'
            with urllib.request.urlopen(url, timeout=90) as response:
                data = response.read(50_000_001)
            if hashlib.sha256(data).hexdigest() != expected:
                raise RuntimeError(f'Checksum mismatch: {filename}')
            temporary = target.with_suffix('.tmp')
            temporary.write_bytes(data)
            temporary.replace(target)
        license_url = f'https://raw.githubusercontent.com/opencv/opencv_zoo/{COMMIT}/models/{folder}/LICENSE'
        with urllib.request.urlopen(license_url, timeout=30) as response:
            (root / f'{folder}-LICENSE.txt').write_bytes(response.read(100_000))
        print(f'Verified {filename}')


if __name__ == '__main__':
    main()
