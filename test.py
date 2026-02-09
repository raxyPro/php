from google import genai

client = genai.Client(api_key="AIzaSyBe1KhFjq5tZpy8inOxZxVM9DVyk")

response = client.models.generate_content(
    model="gemini-3-flash-preview",
    contents="Explain how AI works in a few words",
)

print(response.text)
