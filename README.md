# KYC Utilities
IGM Self-KYC-Util is a set of tools to help developers consume services from IGM (www.intergreatme.com)

Making use of the IGM KYC portal works off of a service provider sending us a whitelist entry to kickstart the process. Thereafter, IGM will send various responses back to the service provider as the user goes through the process. The intent with being verbose is to ensure the service provider can provide adequate support to the user to reduce the need for IGM to facilitate support.

These are broken into three categories:
- Status:
Status API calls tell the service provider about any system changes that occurred during processing. These could be machine learning processes, agent verification processes, or when we have detected a user requires assistance.
- Feedback:
Feedback API calls tell the service provider information about what the user is experiencing on the front end. These could be issues with the types of documents being uploaded, issues with address/liveliness etc.
- Completion:
Completion is the final step of the transaction where we send the payload of information to the service provider with the KYC result included.

We have also included our ID validation util.
