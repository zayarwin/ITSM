FROM python:3.12-slim

WORKDIR /app

# Install system dependencies needed for netmiko/paramiko/ping3
RUN apt-get update && apt-get install -y --no-install-recommends \
    iputils-ping \
    openssh-client \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

EXPOSE 8001

# Run as root so raw ICMP sockets work (capabilities are granted in compose)
CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8001"]
