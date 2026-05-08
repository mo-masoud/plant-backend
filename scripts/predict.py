import os
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'
os.environ['TF_ENABLE_ONEDNN_OPTS'] = '0'

import sys
import json
import numpy as np
from tensorflow.keras.models import load_model
from tensorflow.keras.preprocessing import image
from tensorflow.keras.applications.vgg19 import preprocess_input

model = load_model(os.path.join(os.path.dirname(__file__), 'leaf_model.keras'))

# Class names (must match LabelEncoder order from training)
class_names = ['Arjun Leaf', 'Curry Leaf', 'Marsh Pennywort Leaf', 'Mint Leaf', 'Neem Leaf', 'Rubble Leaf']

# Get image path
img_path = sys.argv[1]

# Load and preprocess
img = image.load_img(img_path, target_size=(224, 224))
img_array = image.img_to_array(img)
img_array = np.expand_dims(img_array, axis=0)
img_array = preprocess_input(img_array)

# Predict
predictions = model.predict(img_array, verbose=0)
predicted_class = np.argmax(predictions[0])
confidence = float(predictions[0][predicted_class])

# Output
result = {'class': class_names[predicted_class], 'confidence': confidence}
print(json.dumps(result))
