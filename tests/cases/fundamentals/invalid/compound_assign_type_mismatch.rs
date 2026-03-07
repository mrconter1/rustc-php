// error: compound assignment requires compatible types

fn main() {
    let mut x: i32 = 5;
    x += "hello";
}
