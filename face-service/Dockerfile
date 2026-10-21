FROM python:3.11-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 FACE_MODEL_DIR=/models
WORKDIR /service
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt
COPY download_models.py ./
RUN python download_models.py
COPY face_service ./face_service
RUN useradd --uid 10001 --create-home face
USER face
EXPOSE 8000
CMD ["uvicorn", "face_service.api:from_env", "--factory", "--host", "0.0.0.0", "--port", "8000", "--workers", "1", "--limit-concurrency", "8", "--no-access-log"]
