// exit: 3

fn main() {
    let mut result: i32 = 0;
    for i in 0..10 {
        if i == 3 {
            result = i;
            break;
        }
    }
    exit(result);
}
